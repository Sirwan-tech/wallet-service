# Wallet Service

A small internal HTTP service that tracks account balances and money movement.
PHP 8.3 / Laravel 12 / MySQL 8.

All money is handled as **integer minor units** (cents). No floating point
appears anywhere in the money path.

> **Base path:** every endpoint lives under `/api`, Laravel's conventional API
> prefix — so the specification's `POST /accounts` is `POST /api/accounts` here.
> See [Deviations from the specification](#deviations-from-the-specification).

---

## Quick start

```bash
docker compose up
```

That is the whole setup. From a clean checkout, with no `.env` file and no
`vendor/` directory, the container creates a `.env` from `.env.example`,
generates an `APP_KEY` if there is none, runs the migrations, and serves on
**http://localhost:8000**.

Create an account and deposit into it:

```bash
curl -X POST http://localhost:8000/api/accounts \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"first_name":"Alice","last_name":"Adams","email":"alice@example.com","phone":"+9647701234567","password":"Str0ng-Passw0rd!","currency":"USD"}'
```

```bash
curl -X POST http://localhost:8000/api/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"login":"alice@example.com","password":"Str0ng-Passw0rd!"}'
```

```bash
curl -X POST http://localhost:8000/api/accounts/{id}/deposits \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'Authorization: Bearer {token}' -H 'Idempotency-Key: 4f1b-…' \
  -d '{"amount":10000}'
```

### Running the tests

```bash
make test
```

or, without `make`:

```bash
docker compose up -d --wait db_test
docker compose run --rm app php artisan test
```

**71 tests, all passing.** They run against a real MySQL 8 database in a
dedicated `db_test` container, from a clean checkout, with no seeded data and no
dependence on execution order. See [TESTING.md](TESTING.md) for the plan, the
manual test session, and the open defects.

### Ports

| Service | Port | Purpose |
|---|---|---|
| `app` | 8000 | The HTTP API |
| `db` | 3307 | MySQL, development schema `wallet` |
| `db_test` | 3308 | MySQL, test schema `wallet_test` |

The database credentials in `.env.example` and in the compose defaults are
throwaway local development values for a database that only exists inside this
compose stack. `.env` itself is gitignored and has never been committed.

---

## API

Every response uses `application/json`. Errors use a single envelope:

```json
{ "error": { "code": "insufficient_funds", "message": "Insufficient funds for this operation." } }
```

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/accounts` | — | Create an account |
| `POST` | `/api/login` | — | Exchange email **or** phone + password for a token |
| `GET` | `/api/accounts/{id}` | Bearer | Fetch an account and its current balance |
| `POST` | `/api/accounts/{id}/deposits` | Bearer + `Idempotency-Key` | Credit an account |
| `POST` | `/api/accounts/{id}/withdrawals` | Bearer + `Idempotency-Key` | Debit an account |
| `POST` | `/api/transfers` | Bearer + `Idempotency-Key` | Move funds between two accounts |
| `GET` | `/api/accounts/{id}/transactions` | Bearer | Paginated history, newest first |
| `GET` | `/api/me` | Bearer | The authenticated account |
| `POST` | `/api/logout` | Bearer | Revoke the current token |

**Amounts** are integers in minor units and must be at least 1.
**Currencies** are one of `USD`, `IQD`, `EUR`, `GBP` (`config/wallet.php`).
**Pagination**: `?per_page=` with a default of 20 and a maximum of 100,
`?page=` for the offset.

### Status codes

| Code | When |
|---|---|
| 201 | An account or a transaction was created — including a replayed idempotent request, which returns the original result |
| 200 | A read, or a successful login |
| 400 | `Idempotency-Key` header missing on a money endpoint |
| 401 | No token, an invalid token, or bad credentials |
| 403 | The account is not yours (`forbidden`), or the account is frozen (`account_frozen`) |
| 404 | No such account |
| 409 | An `Idempotency-Key` was reused with a different payload |
| 429 | A rate limit was exceeded (`rate_limited`) |
| 422 | Validation failed, insufficient funds, or a currency mismatch |

---

## Design decisions

**Money is an integer, and a value object.** `App\Domain\Money` holds an `int`
of minor units and an uppercase 3-letter code. It is immutable and `readonly`,
its constructor is private, and arithmetic between different currencies throws.
Because it depends on nothing, the arithmetic and boundary rules are unit-tested
with no framework and no database — 19 tests that run in well under a second.

**Balances change only inside a locked transaction.** Every money operation in
`App\Services\WalletService` opens a `DB::transaction()` and re-reads the account
with `lockForUpdate()` *inside* it, so the read, the rule check and the write are
one atomic step. Two simultaneous withdrawals of 60 against a balance of 100
produce exactly one success and one rejection — proved by `ConcurrencyTest`,
which spawns real OS processes rather than simulating concurrency in one thread.

**A transfer is one transaction with two legs.** Both accounts are locked, both
rows are written, and both ledger entries share a `transfer_id` so the pair is
recoverable. Any failure rolls back the whole thing: a rejected transfer leaves
*no* record, which the tests assert explicitly rather than only checking the
balances.

**The ledger is append-only, and enforced.** `Transaction` sets
`const UPDATED_AT = null`, records `balance_after` on every row so a statement
can be replayed without recomputation, and throws on `updating` and `deleting`.
Corrections are new rows, never edits. In a real deployment the database
privileges should say the same thing.

**Idempotency is middleware, not business logic.** `HandleIdempotency` sits in
front of the three money endpoints, so `WalletService` never has to know that
retries exist. A replay returns the stored response; a key reused with a
different payload is a 409.
This layer is also where the service's most serious defects were found, and
where the most work went into closing them — see
[Known defects](#known-defects).

**UUID primary keys.** Ordered UUIDs (`HasUuids`), so account and transaction
identifiers are safe to expose and are not guessable or countable.

**Errors are one shape, declared in one place.** All the domain exceptions are
mapped to status codes in `bootstrap/app.php`, so controllers stay free of error
formatting.

### Project layout

```
app/
  Domain/Money.php              value object: integer minor units, currency rules
  Services/WalletService.php    deposit / withdraw / transfer, locking, ledger writes
  Http/Middleware/HandleIdempotency.php
  Http/Controllers/            thin: validate, delegate, present
  Exceptions/                  InsufficientFunds, CurrencyMismatch, AccountFrozen, NotAccountOwner
config/wallet.php              allowed currencies, pagination bounds
tests/
  Unit/MoneyTest.php           no framework, no database
  Feature/                     HTTP + real MySQL, plus the concurrency suite
  Support/concurrent_operation.php   one wallet operation per OS process
```

---

## Deviations from the specification

Stated plainly, because a reviewer following the specification will hit these
before anything else.

**1. Endpoints are under `/api`.** `POST /accounts` is `POST /api/accounts`. It
is Laravel's default prefix and changing it buys nothing, but the literal paths
in the specification return 404.

**2. `POST /accounts` does not accept the specification's payload.** The spec
says an account takes an owner name and a currency code. This implementation
requires `first_name`, `last_name`, `email`, `phone`, `password` and `currency`.
`owner_name` is still returned on every account response as a computed field.
Recorded as BUG-14 in TESTING.md.

**3. Authentication was built, and the specification said not to build it.**
This is the deviation I would most like back. The spec lists authentication as
out of scope and warns it "will not earn extra credit and will cost you time".
I added it anyway — login by email or phone, Sanctum tokens, `/me`, `/logout` —
and it cost more than time:

- five of the six required endpoints now answer 401 to a spec-conformant client;
- `accounts.email` and `accounts.phone` are unique, so one owner cannot hold both
  a USD and a EUR wallet;
- `Account` extends the framework's `Authenticatable`, which welds the domain
  model to the framework;
- and it introduced **BUG-07**: `auth:sanctum` proved identity but nothing
  authorised the request, so any account holder could read and drain any other
  account. Found by hand — see MT-22 and MT-23 in TESTING.md — and fixed before
  submission with an ownership guard on every account route.

Fixing the hole made the service safe. Removing the layer would make it
*correct*, and that is still the first item on the list below.

**4. A `frozen` account state exists but is unreachable.** No endpoint can set
it. It is dead code from the same excursion, and it should be removed rather
than finished.

---

## Known defects

TESTING.md documents all fourteen defects with reproduction steps and severity
reasoning, rather than leaving them for a reviewer to find. **Twelve were found
and fixed before submission; two remain open.** The reports are kept in full,
each with a Fixed note, because how a defect was found says more than the diff
that closed it.

**Found and fixed**

| ID | Severity | What it was |
|---|---|---|
| BUG-04 / BUG-05 | Critical | An idempotency key was fingerprinted from the request **body alone**, so the same key on a different account or endpoint replayed the wrong stored response and the money silently never moved. The fingerprint now covers method + path + body |
| BUG-07 | Critical | No ownership check — any authenticated caller could read and drain any account. Every account route now compares the caller against the target and answers `403 forbidden` |
| BUG-08 | High | The idempotency guard was a non-atomic check-then-insert. The key is now reserved *before* the operation, inside the same transaction, so a concurrent duplicate loses on the unique index |
| BUG-03 | High | `?per_page=-1` produced a MySQL syntax error and a 500. The page size is clamped on both sides, reading its bounds from `config/wallet.php` |
| BUG-02 / BUG-09 | Medium | 404, 405 and unhandled errors escaped the JSON envelope, and a plain `curl` to an unknown route received HTML. Renderers were retyped onto the exceptions Laravel actually raises, JSON is forced for `/api/*`, and a catch-all `Throwable` closes the rest |
| BUG-06 | Medium | A key whose first use returned 4xx was never recorded and could be reused with a different payload. Every terminal outcome below 500 is now recorded; a 5xx deliberately stays retryable |
| BUG-10 / BUG-01 | Medium | `amount` had no ceiling and `Money::add` could overflow into a float and a 500. There is now a domain maximum and an explicit overflow guard, both surfacing as 422 |
| BUG-11 | Medium | "Newest first" had no tiebreaker, so pagination could duplicate or skip rows. The query orders by `created_at` then `id` |
| BUG-13 | Low | `withdraw()` was the only money path that skipped the frozen check. All three now carry the same guards |

**Still open**

| ID | Severity | Summary |
|---|---|---|
| BUG-12 | Low | The history endpoint returns Laravel's raw paginator while every other endpoint returns a hand-shaped body, and each row carries an always-null `idempotency_key` column. Cosmetic; changing the shape this late would have meant rewriting four tests for no behavioural gain |
| BUG-14 | Medium | `POST /accounts` does not accept the specification's payload — a consequence of the authentication extension, not an oversight. See [Deviations](#deviations-from-the-specification) |

Every fixed defect is now covered by a test that asserts the corrected
behaviour, so none of them can silently regress.

---

## Trade-offs accepted

**Business logic still needs a database to test.** Only `Money` is genuinely
framework-free. The balance rule lives inside `WalletService`, wrapped in
`DB::transaction()` with a row lock, so rule 1 cannot be asserted without a live
database. Mocking it would have tested the mock rather than the locking, so I
chose an honest integration test over a hollow unit test — but the specification
asks for business logic testable without a database, and today only the value
object meets that bar.

**The concurrency suite is slow.** It spawns sixteen OS processes across three
tests, each booting Laravel, and adds about thirty seconds to the run. A faster
harness would have been possible; a *convincing* one would not, and rule 7 is
the highest-risk requirement in the service.

**Some tests were written to pin a defect, then rewritten once it was fixed.**
That churn is intentional. A green suite that quietly agrees with a bug is worse
than a test that names it, and rewriting the assertion is what makes the fix a
visible, deliberate change instead of a silent one. The docblocks kept the
history: each says what the defect was and what closed it.

**A security layer was added that the specification did not ask for.**
Per-endpoint rate limiting, CORS, security response headers, Unicode-aware input
validation and a domain ceiling on amounts. None of it is required by the brief
and none of it is offered as credit — it is documented here and in TESTING.md's
Q14 because an undocumented layer in a submission is worse than an owned one.
The cost is honest: it is more surface than the task called for, and it is the
second thing (after authentication) that I would strip to bring the service back
to exactly what was asked.

**The container serves with `php artisan serve`.** Single-worker, so concurrent
requests are handled one at a time. It keeps `docker compose up` to one command
with no nginx or php-fpm configuration, but it also means concurrency cannot be
demonstrated over HTTP — which is why the concurrency tests drive the service
layer in parallel processes instead, and why BUG-08's race was fixed from a
reading of the code rather than from a reproduction.

**Authentication was kept rather than removed.** Fixing BUG-07 made the
authorization boundary real, but the layer itself should not exist — it was out
of scope. Removing it this late would have invalidated a large part of the test
suite for no gain the specification asked for, so it stays, documented, as a
deviation rather than a feature.

---

## What I would do with another week

In this order, because this is the order in which the risk falls.

1. **Remove the authentication extension, and the security layer with it.**
   Neither was asked for. Removing them restores the specification's
   create-account payload (BUG-14), drops the unique email/phone constraint that
   stops one owner holding two currencies, unwelds `Account` from the framework's
   `Authenticatable`, and takes the service back to the surface the brief
   describes. BUG-07 is already closed, but one deletion would make that boundary
   unnecessary rather than merely correct.
2. **Serve behind php-fpm and nginx, then prove BUG-08.** The reservation-row fix
   is sound but it rests on a reading of the code: `php artisan serve` is
   single-worker, so I could never drive two concurrent requests through the full
   middleware stack to reproduce the original race. That is the one claim in this
   repository backed by an argument rather than a test, and it is the gap I would
   close first.
3. **Add `UNIQUE(account_id, idempotency_key)` on `transactions`** as a
   ledger-level backstop, and populate the column — it exists, is fillable, and
   is never written, which is half of BUG-12.
4. **Extract the balance rule into a pure function** over
   `(balanceMinor, amountMinor, currency)`, so rule 1 is unit-testable with no
   database and `WalletService` becomes a thin persistence shell around it. Today
   only `Money` meets the specification's "testable without a live database" bar,
   and that is the weakest point in the Structure of this submission.
5. **Shape the history response like every other endpoint** and finish BUG-12.
6. **Give idempotency keys a lifetime** — a retention window and an explicit
   rejection of keys older than it, instead of an unbounded table.
7. **Add a per-currency minor-unit exponent.** `1000` means $10.00 in USD and
   1000 IQD, and nothing in the schema records which. Nothing renders a balance
   today, so it does not bite yet; the moment anything does, it will.

---

## Configuration

All configuration is through environment variables. `docker compose` supplies
working defaults, so nothing has to be set by hand; anything in `.env` overrides
them.

| Variable | Default | Purpose |
|---|---|---|
| `DB_DATABASE` | `wallet` | Application schema |
| `DB_USERNAME` / `DB_PASSWORD` | local dev values | MySQL credentials |
| `APP_KEY` | generated on first boot, then reused | Laravel encryption key |
| `APP_DEBUG` | `true` | Set to `false` outside local development — with it on, 500 responses include stack traces |
| `WALLET_MAX_AMOUNT_MINOR` | `1000000000000` | Domain ceiling on any single amount, in minor units |
| `RATE_LIMIT_LOGIN_PER_MINUTE` | `5` | Failed logins per credential + IP |
| `RATE_LIMIT_LOGIN_IP_PER_MINUTE` | `30` | Failed logins per IP |
| `RATE_LIMIT_REGISTRATION_PER_MINUTE` / `_PER_HOUR` | `3` / `20` | Account creations per IP |
| `RATE_LIMIT_MONEY_PER_MINUTE` | `10` | Money operations per account |
| `RATE_LIMIT_MONEY_IP_PER_MINUTE` | `60` | Money operations per IP |
| `RATE_LIMIT_AUTH_POST_PER_MINUTE` | `30` | Other authenticated POSTs per account |

The allowed currencies and the pagination bounds live in `config/wallet.php`
rather than the environment, because they are product decisions rather than
deployment settings.
