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
  -d '{"first_name":"Alice","last_name":"Adams","email":"alice@example.com","phone":"+9647701234567","password":"secret123","currency":"USD"}'
```

```bash
curl -X POST http://localhost:8000/api/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"login":"alice@example.com","password":"secret123"}'
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

**63 tests, all passing.** They run against a real MySQL 8 database in a
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
| 403 | The account is frozen |
| 404 | No such account |
| 409 | An `Idempotency-Key` was reused with a different payload |
| 422 | Validation failed, insufficient funds, or a currency mismatch |

---

## Design decisions

**Money is an integer, and a value object.** `App\Domain\Money` holds an `int`
of minor units and an uppercase 3-letter code. It is immutable and `readonly`,
its constructor is private, and arithmetic between different currencies throws.
Because it depends on nothing, the arithmetic and boundary rules are unit-tested
with no framework and no database — 17 tests that run in well under a second.

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

**The ledger is append-only.** `Transaction` sets `const UPDATED_AT = null` and
records `balance_after` on every row, so a statement can be replayed and
reconciled without recomputation. Corrections are meant to be new rows, never
edits.

**Idempotency is middleware, not business logic.** `HandleIdempotency` sits in
front of the three money endpoints, so `WalletService` never has to know that
retries exist. A replay returns the stored response; a key reused with a
different payload is a 409.
*This is also where the service's most serious defects are — see
[Known defects](#known-defects) before relying on it.*

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
  Exceptions/                  InsufficientFunds, CurrencyMismatch, AccountFrozen
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
- and it introduced **BUG-07**: `auth:sanctum` proves identity but nothing
  authorises the request, so any account holder can read and drain any other
  account. Verified by hand — see MT-22 and MT-23 in TESTING.md.

The first item on the list below is to remove it.

**4. A `frozen` account state exists but is unreachable.** No endpoint can set
it. It is dead code from the same excursion, and it should be removed rather
than finished.

---

## Known defects

The service is not defect-free, and TESTING.md documents all fourteen with
reproduction steps and severity reasoning rather than leaving them for a
reviewer to find. The ones that matter:

| ID | Severity | Summary |
|---|---|---|
| BUG-04 / BUG-05 | Critical | An idempotency key is fingerprinted from the request **body alone**, so the same key on a different account or a different endpoint replays the wrong stored response and the money silently never moves |
| BUG-07 | Critical | No ownership check: any authenticated caller can read and drain any account |
| BUG-08 | High | The idempotency guard is a non-atomic check-then-insert, so concurrent same-key requests can both apply (found by inspection, **not** reproduced under load) |
| BUG-03 | High | `?per_page=-1` produces a MySQL syntax error and a 500 |

Six of the fourteen are pinned by tests that assert the current behaviour, with
a docblock naming the bug and the fix, so that correcting them is a visible,
deliberate change rather than a silent one.

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

**The pinned-defect tests will need rewriting when the defects are fixed.** That
is intentional. A green suite that quietly agrees with a bug is worse than a
test that names it.

**The container serves with `php artisan serve`.** Single-worker, so concurrent
requests are handled one at a time. It keeps `docker compose up` to one command
with no nginx or php-fpm configuration, but it also means concurrency cannot be
demonstrated over HTTP — which is why the concurrency tests drive the service
layer in parallel processes instead, and why BUG-08 could not be reproduced.

**The pagination bounds are hardcoded in the controller.** `config/wallet.php`
declares 20 and 100, but `AccountController` does not read it. The config is
currently decorative.

---

## What I would do with another week

In this order, because this is the order in which the risk falls.

1. **Remove the authentication extension.** It closes BUG-07, restores the
   specification's create-account payload, and removes the unique email/phone
   constraint that stops one owner holding two currencies. One deletion fixes
   four separate problems.
2. **Make idempotency correct.** Fingerprint the method and path alongside the
   body, scope the record to the caller, and reserve the key *inside* the same
   transaction as the money movement so a concurrent retry loses on the unique
   index instead of double-applying. Add `UNIQUE(account_id, idempotency_key)` on
   `transactions` as a ledger-level backstop — the column already exists and is
   never written. Then rewrite the pinned tests to assert 409.
3. **Extract the balance rule into a pure function** over
   `(balanceMinor, amountMinor, currency)`, so rule 1 is unit-testable with no
   database and `WalletService` becomes a thin persistence shell around it.
4. **Close the error contract.** A catch-all renderer so 404, 405, 500 and
   malformed bodies all use the same envelope, and retype the not-found renderer
   which is currently dead code.
5. **Fix the edges the tests already name:** clamp `per_page`, add an ordering
   tiebreaker on `id` so "newest first" is a total order, bound `amount` and
   guard `Money::add` against overflow, and shape the history response like every
   other endpoint.
6. **Serve behind php-fpm and nginx**, so concurrency can be demonstrated over
   real HTTP and BUG-08 can be proved rather than reasoned about.
7. **Give idempotency keys a lifetime** — a retention window and an explicit
   rejection of keys older than it, instead of an unbounded table.

---

## Configuration

All configuration is through environment variables. `docker compose` supplies
working defaults, so nothing has to be set by hand; anything in `.env` overrides
them.

| Variable | Default | Purpose |
|---|---|---|
| `DB_DATABASE` | `wallet` | Application schema |
| `DB_USERNAME` / `DB_PASSWORD` | local dev values | MySQL credentials |
| `APP_KEY` | generated on first boot | Laravel encryption key |
| `APP_DEBUG` | `true` | Set to `false` outside local development — with it on, 500 responses include stack traces |
