# TESTING.md

How this service was tested, what was deliberately left untested, what broke,
and where the specification left me guessing.

---

## 1. Running the tests

```bash
make test
```

or, equivalently, without `make`:

```bash
docker compose up -d --wait db_test
docker compose run --rm app php artisan test
```

The suite runs from a clean checkout with no manual setup and no seeded data.
It executes against a **real MySQL 8 database** (`db_test`, a dedicated
container, schema `wallet_test`), never SQLite and never an in-memory store —
in-memory SQLite would silently compile `lockForUpdate()` to an empty string
and make the concurrency test meaningless.

`tests/TestCase.php` refuses to start unless `DB_DATABASE` is `wallet_test`,
because the suite drops and recreates every table. That guard exists because
the first attempt at wiring the test database *silently targeted the
development database*: PHPUnit's `<env force="true">` writes only `putenv()`
and `$_ENV`, while Laravel reads `$_SERVER` first, so the container's own
`DB_HOST=db` won. The fix was matching `<server>` entries in `phpunit.xml`; the
guard makes a regression loud instead of destructive.

**Current state: 71 tests, 71 passing, 275 assertions.**

| Suite | File | Tests | What it covers |
|---|---|---:|---|
| Unit | `tests/Unit/MoneyTest.php` | 19 | The `Money` value object — no Laravel, no database |
| Feature | `tests/Feature/WalletServiceTest.php` | 4 | The balance rule at the service layer |
| Feature | `tests/Feature/AccountApiTest.php` | 27 | Create / read / deposit / withdraw / history / ownership / error contract over HTTP |
| Feature | `tests/Feature/TransferApiTest.php` | 10 | Transfers, atomicity, the currency rule, ownership |
| Feature | `tests/Feature/IdempotencyTest.php` | 8 | Rule 4 in all its forms |
| Feature | `tests/Feature/ConcurrencyTest.php` | 3 | Rule 7, under real parallel load |

**Twelve of the fourteen defects in section 4 were found and then fixed** before
submission. Their reports are kept in full, each with a Fixed note, because how
a defect was found is more informative than the diff that closed it — and
because several were found by the tests themselves, which is the point of
writing them. Two remain: BUG-12, a cosmetic response-shape inconsistency, and
BUG-14, a deliberate deviation from the specification.

### A note on the security layer

The service also carries per-endpoint rate limiting, CORS configuration,
security response headers, Unicode-aware input validation and a domain ceiling
on amounts. **None of this was asked for.** The specification does not mention
it, and it is not offered as credit — it is recorded here because leaving an
undocumented layer in a submission would be worse than owning it. The relevant
consequence for testing is in Q14.

---

## 2. Test plan

### 2.1 Risk assessment — what hurts most if it breaks

I ranked the system by *what a failure costs*, not by how much code it touches,
and put the testing effort in that order.

| Rank | Risk | Why it is at this rank | Where it is tested |
|---|---|---|---|
| 1 | **Money created or destroyed under concurrency** | Silent, unrecoverable, and invisible in the balance column — see §2.3. Two callers can both be told "success" while only one debit lands. | `ConcurrencyTest` (real parallel processes) |
| 2 | **A retry applying an operation twice** | Network retries are routine; a double debit is a customer-visible loss the client cannot detect. | `IdempotencyTest` |
| 3 | **A partial transfer** | One leg without the other corrupts the ledger permanently; every later reconciliation is wrong. | `TransferApiTest` — a rejected transfer must record *neither* leg |
| 4 | **A negative balance** | The core invariant of the product. Cheap to test, catastrophic to miss. | `MoneyTest`, `WalletServiceTest`, `AccountApiTest` |
| 5 | **Authorization** | Any authenticated caller could read and drain any account (BUG-07) until it was fixed. This ranks below the ledger risks only because authentication was out of scope to begin with. | Found by hand (MT-22, MT-23); now covered by four tests |
| 6 | **Error contract drift** | A client that cannot tell "insufficient funds" from "server exploded" will retry the wrong things. | `AccountApiTest`, `TransferApiTest` |
| 7 | Pagination correctness | Wrong page boundaries mislead a human reading a statement, but no money moves. | `AccountApiTest` |

### 2.2 Choosing the level for each problem

- **Unit, no database.** Everything that is pure arithmetic and invariants lives
  in `App\Domain\Money`, and `MoneyTest` extends PHPUnit's own `TestCase` rather
  than Laravel's. It boots no framework and touches no database, which is the
  specification's requirement that business logic be testable without a live
  database. This suite runs in well under a second.
- **Service level, real database.** `WalletServiceTest` exercises the balance
  rule where it actually lives — inside `DB::transaction()` with a row lock.
  This cannot be done without a database, and mocking it would test the mock
  rather than the locking.
- **HTTP, real database.** Everything a client can observe — status code, error
  envelope, response body, and the resulting database state — is tested through
  the real endpoints. Assertions check the body and the ledger, never just
  `200 OK`.
- **Multi-process, real database.** Rule 7 cannot be demonstrated inside one PHP
  process, because a process cannot contend with itself for a row lock, and
  `pcntl` is not compiled into the `php:8.3` image. `ConcurrencyTest` therefore
  spawns real OS processes through `Symfony\Component\Process`, each with its
  own connection, released together by a shared start timestamp.

### 2.3 Why `ConcurrencyTest` asserts what it does

This is the single most important design decision in the suite.

The obvious assertion for "two withdrawals of 60 against a balance of 100" is
`assertSame(40, $balance)`. **That assertion is worthless.** I removed
`->lockForUpdate()` from `WalletService::withdraw()` and measured the unlocked
behaviour over eight runs: both processes read 100, both wrote `100 - 60`, and
the balance still read **40 every single time**. What actually changed was that
both callers were told they had succeeded and *two* withdrawal rows were
written — 120 left an account that held 100.

So the tests assert the **outcome counts** (exactly one `APPLIED`, exactly one
`REJECTED`) and the **ledger row count**, and treat the balance as a secondary
check. With the lock removed all eight runs double-spent; with it in place the
result is stable.

### 2.4 What I consciously did NOT automate, and why

- **Ownership / authorization (BUG-07) — was not automated, now is.** I
  found this by hand (MT-22, MT-23) and deliberately did *not* write a test
  pinning the broken behaviour: unlike the idempotency defects it is not a
  subtle interaction worth preserving, it is a missing check. Once it was fixed
  the four tests that belong there were written, asserting 403 on reading,
  withdrawing from, listing, and transferring out of somebody else’s account.
- **The idempotency race under concurrency (BUG-08) — found by reading, fixed,
  still not reproduced.** I could describe the interleaving exactly from the
  code, and the fix (a reservation row written inside the same transaction as
  the money movement) follows from it. What I could not do is *prove* the
  original race end to end: that needs concurrent requests through the full HTTP
  middleware stack, and the container serves with a single-worker
  `php artisan serve` that handles requests one at a time. Building an
  in-process HTTP-kernel harness was not the best use of the remaining time. The
  fix is reasoned and the surrounding behaviour is tested; the race itself is
  not, and the report says so rather than implying coverage I do not have.
- **Load and performance.** Out of scope; the concurrency tests are about
  correctness under contention, not throughput.
- **The `frozen` account state.** No endpoint can set `status = 'frozen'`, so
  the feature is unreachable over the API. Testing it would test dead code.
  (It is also outside the specification — see BUG-13.)
- **Auth token lifecycle** (expiry, revocation, `/logout`, `/me`). Authentication
  is out of scope; I did not spend test budget on an extension the reviewer did
  not ask for.
- **Currency conversion, multi-tenancy, UI, CI.** Explicitly out of scope.

### 2.5 Where I drew the automated / manual line

Automated everything **deterministic and repeatable**: status codes, bodies,
balances, ledger rows, boundaries, and the two concurrency scenarios.

Left to manual testing everything about **how the system behaves for a human
holding a terminal**: what a plain `curl` with no `Accept` header actually
receives, whether a stack trace leaks, whether the specification's own example
payload is accepted, and whether one user can reach another user's data. Those
are exploratory questions — the value is in *noticing*, not in re-running.

Section 3 is a real session against the running service, executed on
2026-08-28 with `docker compose up -d`. The "actual" column is copied from the
responses, not from expectation.

---

## 3. Manual test cases

**Environment for all cases:** `docker compose up -d` on Docker Desktop
(Windows 11); app container `php:8.3-fpm` serving `http://localhost:8000`;
MySQL 8 (`db`, schema `wallet`); `APP_DEBUG=true`; client `curl 8.12.1`.
All paths sit under the `/api` prefix.

**Common preconditions:** three accounts created at the start of the session —
`ALICE` (USD), `BOB` (USD), `EVA` (EUR), all starting at 0 — and bearer tokens
obtained for ALICE and BOB via `POST /api/login`.

| ID | Title | Preconditions | Steps | Expected | Actual | Result |
|---|---|---|---|---|---|---|
| MT-01 | Currency code is normalised | none | `POST /accounts` with `"currency":"usd"` | 201, stored as `USD`, balance 0 | 201, `"currency":"USD"`, `"balance":0` | **Pass** |
| MT-02 | The specification's own create payload | none | `POST /accounts` with `{"owner_name":"Spec Reviewer","currency":"USD"}` | 201 per the spec | 422 `validation_failed`; `first_name`, `last_name`, `email`, `phone`, `password` all reported required | **Fail** (BUG-14) |
| MT-03 | Unsupported currency is rejected | none | `POST /accounts` with `"currency":"JPY"` | 422 naming the accepted codes | 422 `validation_failed`, "The currency must be one of: USD, IQD, EUR, GBP." | **Pass** |
| MT-04 | Duplicate email is rejected | ALICE exists | `POST /accounts` reusing ALICE's email | 422 | 422 `validation_failed`, "The email has already been taken." | **Pass** |
| MT-05 | Reading an account needs a token | ALICE exists | `GET /accounts/{ALICE}` with no `Authorization` | 401 in the error envelope | 401 `{"error":{"code":"unauthenticated",...}}` | **Pass** |
| MT-06 | Login works with a phone number | ALICE exists | `POST /login` with the phone as `login` | 200 + token | 200, token issued | **Pass** |
| MT-07 | Wrong password is rejected | ALICE exists | `POST /login` with a bad password | 401, no user enumeration | 401 `invalid_credentials`, same message as for an unknown login | **Pass** |
| MT-08 | Reading your own account | ALICE token | `GET /accounts/{ALICE}` | 200 with balance and currency | 200, `"balance":0`, `"currency":"USD"` | **Pass** |
| MT-09 | Money endpoints demand an idempotency key | ALICE token | `POST /accounts/{ALICE}/deposits` with no `Idempotency-Key` | 400 | 400 `idempotency_key_required` | **Pass** |
| MT-10 | Deposit credits the account | ALICE token, balance 0 | `POST .../deposits` `{"amount":10000}`, key `k-dep` | 201, `balance_after` 10000 | 201, `"type":"deposit"`, `"balance_after":10000` | **Pass** |
| MT-11 | Replaying a deposit does not double-apply | MT-10 done | Repeat MT-10 byte for byte with the same key | 201 returning the **original** transaction | 201, identical transaction `id` returned (JSON key order differs — it is re-serialised from the stored column) | **Pass** |
| MT-12 | A key reused with a different payload conflicts | MT-10 done | `POST .../deposits` `{"amount":5000}` with key `k-dep` | 409 | 409 `idempotency_conflict` | **Pass** |
| MT-13 | The replay really did not move money | MT-10..12 done | `GET /accounts/{ALICE}` | balance 10000, not 20000 | `"balance":10000` | **Pass** |
| MT-14 | Overdrawing is refused | ALICE balance 10000 | `POST .../withdrawals` `{"amount":999999}` | 422, nothing recorded | 422 `insufficient_funds` | **Pass** |
| MT-15 | Fractional amounts are refused | ALICE token | `POST .../withdrawals` `{"amount":10.5}` | 422 | 422 `validation_failed`, "The amount field must be an integer." | **Pass** |
| MT-16 | Transfer between two USD accounts | ALICE 10000, BOB 0 | `POST /transfers` amount 2500 | 201, both legs, one `transfer_id` | 201, `from.balance_after` 7500, `to.balance_after` 2500, shared `transfer_id` | **Pass** |
| MT-17 | Cross-currency transfer is refused | ALICE USD, EVA EUR | `POST /transfers` ALICE→EVA | 422 | 422 `currency_mismatch`, "cannot operate between USD and EUR" | **Pass** |
| MT-18 | Self-transfer is refused | ALICE token | `POST /transfers` ALICE→ALICE | 422 | 422 `validation_failed`, "to account id field and from account id must be different" | **Pass** |
| MT-19 | Transaction history, newest first | ALICE has a deposit then a transfer | `GET /accounts/{ALICE}/transactions` | 200, newest first, sane page size | 200, `transfer_out` first then `deposit`, `current_page` 1 — **but** the body is Laravel's raw paginator (`first_page_url`, `last_page_url`, `path`, …) and every row exposes an always-null `idempotency_key` column | **Pass with defect** (BUG-12) |
| MT-20 | Negative page size | ALICE has transactions | `GET .../transactions?per_page=-1` | 422, or clamped to a valid size | **500** `SQLSTATE[42000] ... 1064 ... near 'offset 0'`, plus a full stack trace | **Fail** (BUG-03) |
| MT-21 | Unknown account id | ALICE token | `GET /accounts/1111...1111` | 404 in the error envelope | 404, but the body is `{"message":"No query results for model [App\\Models\\Account] ..."}` with the model class and a stack trace — no `error.code` | **Fail** (BUG-02) |
| MT-22 | Reading someone else's account | ALICE token, BOB exists | `GET /accounts/{BOB}` with ALICE's token | 403 | **200** — Bob's balance, email and phone returned to Alice | **Fail** (BUG-07) |
| MT-23 | Withdrawing from someone else's account | ALICE token, BOB balance 2500 | `POST /accounts/{BOB}/withdrawals` `{"amount":100}` with ALICE's token | 403, nothing moves | **201** — 100 debited from BOB, `balance_after` 2400 | **Fail** (BUG-07, critical) |
| MT-24 | Idempotency key reused across accounts | key `k-dep` already used by ALICE | `POST /accounts/{BOB}/deposits` `{"amount":10000}` with **BOB's** token and key `k-dep` | 409, or BOB credited | **201 returning ALICE's transaction record** — Bob is told his deposit succeeded | **Fail** (BUG-04, critical) |
| MT-25 | Confirm MT-24 moved no money | MT-24 done | `GET /accounts/{BOB}` | 12400 if the deposit landed | `"balance":2400` — the 10000 vanished silently | **Fail** (BUG-04, critical) |
| MT-26 | Unknown route, plain curl | none | `curl http://localhost:8000/api/nope` (no `Accept` header) | JSON error envelope | **HTML** — a full `<!DOCTYPE html>` error page from a JSON-only API | **Fail** (BUG-09) |
| MT-27 | Wrong method on a real route | none | `GET /api/transfers` | 405 in the error envelope | 405 with `{"message":"The GET method is not supported...","exception":...}` — wrong shape | **Fail** (BUG-09) |

**Summary: 27 executed, 18 passed, 9 failed.** Note that MT-23 and MT-24
compound: Alice first took 100 from Bob, then Bob's own 10000 deposit was
silently swallowed — his balance went from 2500 to 2400 across two responses
that both said "success".

**Since this session, eight of the nine failures have been fixed.** The table
above is left exactly as recorded on 2026-08-28, because it is the evidence that
found them. The current behaviour is:

| ID | Then | Now |
|---|---|---|
| MT-20 | 500, MySQL error 1064 | 200; the page size falls back to the configured default of 20 |
| MT-21 | 404 with the model class and a stack trace | 404 `{"error":{"code":"not_found"}}` |
| MT-22 | 200 — Bob's details returned to Alice | 403 `forbidden` |
| MT-23 | 201 — 100 debited from Bob | 403 `forbidden`, nothing moves |
| MT-24 | 201 carrying Alice's record | 409 `idempotency_conflict` |
| MT-25 | Bob's 10000 silently vanished | n/a — the request is refused before it can vanish |
| MT-26 | an HTML error page | 404 `{"error":{"code":"not_found"}}` |
| MT-27 | 405 in Laravel's own shape | 405 `{"error":{"code":"method_not_allowed"}}` |

Each of those is now covered by an automated test, so the manual case does not
have to be re-run by hand to stay honest. **MT-02 still fails** — it is BUG-14,
the deliberate deviation, and it is the one failure that is a decision rather
than a defect.

---

## 4. Bug reports

Environment for every report below: `docker compose up -d`, app `php:8.3-fpm`
on `http://localhost:8000`, MySQL 8, Laravel 12, PHP 8.3, `APP_DEBUG=true`,
client `curl 8.12.1`, 2026-08-28.

Severity scale: **Critical** — money is lost, created, or reachable by the
wrong person. **High** — a stated requirement demonstrably does not hold.
**Medium** — a real defect with a narrow trigger, or a dishonest response.
**Low** — correctness is unaffected today, but an invariant is undefended.

| ID | Title | Severity | Status |
|---|---|---|---|
| BUG-04 | Idempotency key scoped to the body alone — a second account's deposit is silently swallowed | Critical | **Fixed late** |
| BUG-05 | The same key across two endpoints swallows the opposite operation | Critical | **Fixed late** |
| BUG-07 | No ownership check — any authenticated caller can read and drain any account | Critical | **Fixed late** |
| BUG-03 | `per_page=-1` crashes the history endpoint with a MySQL syntax error | High | **Fixed late** |
| BUG-08 | The idempotency guard is a non-atomic check-then-insert | High | **Fixed late** |
| BUG-02 | 404 responses escape the error envelope and leak internals | Medium | **Fixed late** |
| BUG-06 | A key whose first use failed becomes reusable with a different payload | Medium | **Fixed late** |
| BUG-09 | Framework errors (unknown route, wrong method) escape the JSON contract | Medium | **Fixed late** |
| BUG-10 | `amount` has no upper bound, so a large deposit returns 500 | Medium | **Fixed late** |
| BUG-11 | "Newest first" is not a deterministic order | Medium | **Fixed late** |
| BUG-14 | `POST /accounts` rejects the payload the specification defines | Medium | **Open** — deliberate deviation |
| BUG-01 | `Money::add()`/`subtract()` bypass the non-negative guard | Low | **Fixed late** |
| BUG-12 | The history response is shaped unlike every other endpoint, and leaks a column | Low | **Open** |
| BUG-13 | `withdraw()` is the only money path that skips the frozen check | Low | **Fixed late** |

Every "Fixed late" row is now covered by a test that asserts the corrected
behaviour, so the fix cannot silently regress. The two open rows are a cosmetic
inconsistency and a decision, not defects that were left unattended.

---

### BUG-01 — `Money::add()` and `Money::subtract()` bypass the non-negative guard
**Severity: Low.** *Found while writing the unit tests, **fixed late**.*

**Steps to reproduce (unit level):** `Money::of(100,'USD')->subtract(Money::of(150,'USD'))`
returns a `Money` whose `minorUnits` is `-50`, and `isNegative()` returns true.

**Expected:** either an exception, or a type that cannot hold a negative amount.
**Actual:** a negative `Money` is constructed silently.

**Cause.** `of()` rejects negative amounts, but `add()` and `subtract()`
construct through the private constructor directly, so the guard never runs.
`isNegative()` is never called anywhere in the codebase.

**Severity reasoning.** Low: no negative balance can reach the database today,
because `WalletService` compares the debit against the balance *before*
subtracting. But the value object does not defend its own invariant, so the
safety of the system rests on every caller remembering to check first. Pinned by
`MoneyTest::test_subtract_currently_produces_a_negative_money_bypassing_the_of_guard`.

**Fix.** Route `add()` and `subtract()` through `self::of()`, and either use
`isNegative()` or delete it.


**Fixed.** `add()` and `subtract()` now return through `self::of()` instead of
the private constructor, so the non-negative guard applies to every path, and
`add()` refuses an operand that would carry the total past `PHP_INT_MAX`.
`MoneyTest::test_subtract_refuses_to_produce_a_negative_money` and
`test_subtracting_the_whole_amount_is_allowed_and_yields_zero` cover the rule
and its boundary.

---

### BUG-02 — 404 responses escape the error envelope and leak internals
**Severity: Medium.** *Found by manual testing (MT-21), **fixed late**.*

**Steps to reproduce:** `GET /api/accounts/11111111-1111-1111-1111-111111111111`
with a valid token.

**Expected:** `404 {"error":{"code":"not_found","message":"Resource not found."}}`
— there is a renderer for exactly this in `bootstrap/app.php:47`.

**Actual:** 404 with
`{"message":"No query results for model [App\\Models\\Account] 1111...","exception":"Symfony\\...\\NotFoundHttpException","file":"...","line":...,"trace":[...]}`.

**Cause.** The renderer is **dead code**. Laravel's exception handler calls
`prepareException()` — which converts `ModelNotFoundException` into
`NotFoundHttpException` — *before* it consults the render callbacks, so a
callback typed on `ModelNotFoundException` can never match.

**Severity reasoning.** Medium: the status code is honest, so a well-written
client still behaves correctly, but the documented contract is not met, the
internal model class is disclosed, and with `APP_DEBUG=true` a full stack trace
including absolute paths is returned. Every not-found path is affected — read,
deposit, withdraw, and transfers to an unknown account. Pinned by
`AccountApiTest::test_an_unknown_account_returns_404_but_not_the_error_envelope`.

**Fix.** Type the callback on `NotFoundHttpException` instead, which also covers
unknown routes and therefore partly addresses BUG-09.


**Fixed.** The renderer is now typed on `NotFoundHttpException` - the exception
Laravel actually reaches the callbacks with - so every not-found path returns
`404 {"error":{"code":"not_found","message":"Resource not found."}}` with no
model class and no stack trace. The same change covers unknown routes, which is
half of BUG-09.
`AccountApiTest::test_an_unknown_account_returns_404_in_the_error_envelope`
asserts the code and that no `exception` key is present.

---

### BUG-03 — `per_page=-1` crashes the transaction history endpoint
**Severity: High.** *Found by manual testing (MT-20), **fixed late**.*

**Steps to reproduce**
1. Create an account and give it at least one transaction.
2. `GET /api/accounts/{id}/transactions?per_page=-1`.

**Expected:** 422, or the value clamped to a legal page size.

**Actual:** **500**, body
`SQLSTATE[42000]: ... 1064 ... near 'offset 0' ... SQL: select * from transactions where account_id = ... order by created_at desc offset 0`,
plus a full stack trace with `APP_DEBUG=true`.

**Cause.** `app/Http/Controllers/AccountController.php:84`:
```php
$perPage = min((int) $request->query('per_page', 20), 100);
```
There is no lower bound. `min(-1, 100)` is `-1`. Laravel's
`Query\Builder::limit()` discards a negative limit, but `forPage()` still emits
`offset(0)`, so the query compiles to a bare `OFFSET` with no `LIMIT`, which
MySQL rejects. No render callback matches `QueryException`, so it surfaces as an
unhandled 500.

*How this was found:* the first hypothesis was that a negative page size would
return the whole history unpaginated. That is the behaviour on PostgreSQL and
SQLite; on MySQL it is a syntax error. The test asserts the real MySQL
behaviour and says so, because the suite is pinned to MySQL.

**Severity reasoning.** High rather than critical because no money moves and no
data is exposed — but it is trivially reachable by any client, it answers a
plain client mistake with a server error, and with debug on it leaks the schema
and absolute file paths.

**Fixed.** `AccountController::transactions` now reads the bounds from
`config/wallet.php` — which until then was declared but never used — and falls
back to the configured default for any value that is not a positive integer:

```php
$perPage = (int) $request->query('per_page', $default);
$perPage = $perPage > 0 ? min($perPage, $max) : $default;
```

`AccountApiTest::test_an_invalid_page_size_falls_back_to_the_configured_default`
now asserts 200 with `per_page` 20 for `-1`, `0` and `abc`, and
`test_transaction_history_caps_the_page_size` still asserts the 100 ceiling.

---

### BUG-04 — An idempotency key is scoped to the request body alone, so a second account's deposit is silently swallowed
**Severity: Critical.** *Found by manual testing (MT-24, MT-25), **fixed late**.*

**Steps to reproduce**
1. As ALICE: `POST /api/accounts/{ALICE}/deposits`, body `{"amount":10000}`,
   header `Idempotency-Key: k-dep`. → 201, Alice credited 10000.
2. As BOB, with his own token: `POST /api/accounts/{BOB}/deposits`, body
   `{"amount":10000}`, header `Idempotency-Key: k-dep`.
3. `GET /api/accounts/{BOB}`.

**Expected:** step 2 is a different operation on a different account, so either
Bob is credited, or the reused key is rejected with 409 as rule 4 requires.

**Actual:** step 2 returns **201** carrying *Alice's* transaction record — her
`id` and her `account_id`. Step 3 shows Bob's balance unchanged. Bob's client
has been told the money arrived; it never did.

**Cause.** `app/Http/Middleware/HandleIdempotency.php:27`:
```php
$requestHash = hash('sha256', $request->getContent());
```
The fingerprint is the body bytes only — no HTTP method, no path, no `{id}`
route parameter — and the stored row is not scoped to a caller. The lookup at
line 30 is a bare `where('idempotency_key', $key)` on a globally unique column.

**Severity reasoning.** The worst class of failure a wallet can have: money a
client believes moved and did not, with a `201` and a plausible body to prove
it. It needs no attacker and no race — two ordinary clients that happen to
generate the same key string, or one client reusing a key across accounts, are
enough. Reconciliation cannot detect it, because the ledger stays internally
consistent.

**Fixed.** The fingerprint now identifies the *operation*, not just the payload
(`HandleIdempotency.php`):

```php
$requestHash = hash('sha256', implode('|', [
    $request->method(),
    $request->path(),
    $request->getContent(),
]));
```

A genuine replay — same method, same path, same body — still returns the
original result, and a key reused against a different account is now the 409
conflict rule 4 requires.
`IdempotencyTest::test_the_same_key_on_a_different_account_is_rejected_as_a_conflict`
asserts the conflict and that neither account moved.

*What is still not done:* the stored row is not scoped to the authenticated
caller. In practice two callers can no longer reach the same fingerprint — the
account id is in the path for deposits and withdrawals and in the body for
transfers, and BUG-07's ownership check now stops anyone acting on an account
that is not theirs — so the residual risk is a spurious 409 between two clients
that generate the same key string, never lost money. Scoping the row to the
caller would remove even that.

---

### BUG-05 — An idempotency key shared across two endpoints swallows the opposite operation
**Severity: Critical.** *Found while writing the idempotency tests, **fixed
late**.* Same root cause as BUG-04, reported separately because it failed in a
different direction.

**Steps to reproduce**
1. `POST /api/accounts/{X}/deposits` `{"amount":10000}` with key `k`. → 201.
2. `POST /api/accounts/{X}/withdrawals` `{"amount":10000}` with the same key `k`.

**Expected:** a withdrawal is not a replay of a deposit. 409, or the debit is
performed.

**Actual:** 201 with `"type":"deposit"`. Both bodies serialise to the identical
bytes `{"amount":10000}`, so the withdrawal is treated as a replay. The account
is never debited.

**Severity reasoning.** Critical for the same reason as BUG-04, and slightly
more insidious: the returned body says `deposit` while the caller asked for a
withdrawal, so even a client that inspects the response is more likely to be
confused than alerted.

**Fixed** by the same change as BUG-04 — the path is now part of the
fingerprint, so `/deposits` and `/withdrawals` can never be replays of one
another.
`IdempotencyTest::test_the_same_key_on_a_different_endpoint_is_rejected_as_a_conflict`
asserts 409 and that the balance is untouched.

---

### BUG-06 — A key whose first use failed becomes reusable with a different payload
**Severity: Medium.** *Found while writing the idempotency tests, **fixed late**.*

**Steps to reproduce**
1. Account balance 0. `POST .../withdrawals` `{"amount":10000}` with key `k`.
   → 422 `insufficient_funds`.
2. Credit the account 10000 using any other key.
3. `POST .../withdrawals` `{"amount":5000}` with the **same** key `k`.

**Expected:** rule 4 says a key reused with a different payload must be rejected
as a conflict. 409.

**Actual:** 201. The withdrawal is applied and the balance becomes 5000.

**Cause.** `HandleIdempotency.php:54` records a row only for 2xx responses, so
after a failure the key is silently free again.

**Severity reasoning.** Medium: it needs an unusual sequence — a failure, then a
state change, then a retry with an altered payload. But the realistic version is
ordinary: a client times out on a request that in fact returned 422, retries
with an adjusted amount under the same key, and moves money it believed had been
rejected. Pinned by
`IdempotencyTest::test_a_key_whose_first_use_failed_is_currently_reusable_with_a_different_payload`.

**Fix.** Persist the outcome of every terminal response, not just 2xx. If failed
attempts should stay retryable that is defensible, but the key and hash must
still be recorded so the conflict check keeps working.


**Fixed.** Every terminal outcome below 500 is now recorded, so a 422 is
replayed as the original 422 and the same key with a changed payload is the 409
the rule requires. Server errors are deliberately still not cached: a 5xx rolls
the reservation back and stays retryable, which is the behaviour a client needs
when it cannot tell whether the operation ran.
`IdempotencyTest::test_a_key_whose_first_use_failed_is_recorded_and_cannot_be_reused`
walks the whole sequence: 422, recorded, replayed, then conflict.

---

### BUG-07 — No ownership check: any authenticated caller can read and drain any account
**Severity: Critical.** *Found by manual testing (MT-22, MT-23), **fixed late**.*

**Steps to reproduce**
1. Create ALICE and BOB. Log in as ALICE and take her token.
2. `GET /api/accounts/{BOB}` with Alice's token.
3. `POST /api/accounts/{BOB}/withdrawals` `{"amount":100}` with Alice's token
   and any idempotency key.
4. `POST /api/transfers` with `"from_account_id":"{BOB}"` and Alice's token.

**Expected:** 403 on steps 2, 3 and 4.

**Actual:** step 2 returns **200** with Bob's balance, email and phone. Step 3
returns **201** and debits Bob's account — verified, `balance_after` 2400 from
2500. Step 4 likewise moves Bob's money.

**Cause.** `routes/api.php` wraps these routes in `auth:sanctum`, which proves
*identity* and nothing more. `AccountController::show`, `deposit`, `withdraw`
and `transactions` take the account id straight from the URL and never consult
`$request->user()`; `TransferController::store` takes `from_account_id` from the
request body. Grepping `app/` for `authorize`, `Gate`, `Policy`, `can(` or
`abort(403)` returns nothing — the only 403 in the codebase is the frozen-login
check in `AuthController`.

**Severity reasoning.** The highest severity available. It is worse than having
no authentication at all, because the presence of a login endpoint invites a
reader to assume a boundary exists where there is none. It also discloses third
parties' personal data (email, phone).

**Fixed.** A single guard in `AccountController`, applied to `show`, `deposit`,
`withdraw` and `transactions`:

```php
private function assertOwns(Request $request, string $id): void
{
    if ($request->user()->getKey() !== $id) {
        throw new NotAccountOwnerException();
    }
}
```

`TransferController` applies the same check to `from_account_id`, and the new
`App\Exceptions\NotAccountOwnerException` is rendered as
`403 {"error":{"code":"forbidden", ...}}` so the refusal uses the same envelope
as every other error. Four tests cover it: reading, listing, withdrawing from,
and transferring out of somebody else's account.

**Ordering note, chosen deliberately.** `show` and `transactions` look the
account up first, so an unknown id still answers 404. The money endpoints check
ownership *before* the lookup, so an unknown id answers 403 — no existence
oracle on the endpoints that move money.

**The better fix, not taken.** Removing the authentication layer altogether
would have closed this and BUG-14 at once, and authentication was out of scope
to begin with. I kept it because removing it this late would have invalidated a
large part of the test suite for no gain the specification asked for. That is a
trade-off, not an endorsement — see Q2.

---

### BUG-08 — The idempotency guard is a non-atomic check-then-insert
**Severity: High.** *Found by reading the code, **fixed late**. It was never reproduced
under load - see the note at the end of this report.*

**Description.** In `HandleIdempotency::handle()` the lookup
(`IdempotencyKey::where(...)->first()`, line 30) and the write
(`IdempotencyKey::create(...)`, line 56) sit in no transaction and take no lock,
and the write happens **after** `$next($request)` has already committed the
money movement. The duplicate-key `QueryException` is then swallowed by an empty
`catch` at line 62.

**Interleaving.** Two identical requests with the same key arrive together. Both
reach line 30 and find nothing, because neither has inserted yet. Both run the
wallet operation and commit. One insert loses the unique-constraint race and its
exception is discarded. The account has been credited or debited twice, and the
service never notices. The row lock in `WalletService` serialises the arithmetic
— so rule 7 still holds — but it does nothing to deduplicate, so rule 4 fails
under exactly the parallel conditions the specification asks about.

**A second, narrower window:** even for a single request, the money transaction
commits before the key row is written. A crash, timeout, or non-JSON response
between the two leaves an applied-but-unrecorded operation that a retry applies
again.

**Expected:** exactly one application. **Actual (by inspection):** two.

**Severity reasoning.** High rather than critical only because I did not prove
it end to end. Demonstrating it needs concurrent requests through the full HTTP
middleware stack, and the container serves with a single-worker
`php artisan serve` that handles requests strictly one at a time — a parallel
`curl` gives the right answer for the wrong reason. I am recording it as an
unproven finding rather than claiming coverage I do not have. If it does occur
in production it is a straightforward double debit, which is critical.

**Fix.** Insert a reservation row for the key *before* calling `$next()`, inside
the same transaction as the money movement, and let the unique index reject the
loser. A `UNIQUE(account_id, idempotency_key)` on `transactions` would give a
second, ledger-level backstop — the column already exists in the schema and in
`$fillable` but is never written.


**Fixed.** The middleware now inserts a reservation row for the key *before*
calling `$next()`, inside the same `DB::transaction` as the money movement, and
updates that row with the real status and body afterwards. A concurrent request
with the same key loses on the unique index instead of executing a second time,
and the reservation and the money commit or roll back together, so the crash
window between them is closed. A 5xx deliberately rolls the whole thing back
rather than caching an unreplayable response.

*Still true, and worth keeping:* this fix is reasoned and unit-covered, but the
race itself was never reproduced end to end. Doing that needs concurrent
requests through the full HTTP middleware stack, and the container serves with a
single-worker `php artisan serve` that handles requests one at a time. The
service-layer concurrency tests prove rule 7; they do not exercise this path.

---

### BUG-09 — Framework errors escape the JSON error contract entirely
**Severity: Medium.** *Found by manual testing (MT-26, MT-27), **fixed late**.*

**Steps to reproduce**
1. `curl http://localhost:8000/api/nope` — a plain curl, no `Accept` header.
2. `curl -X GET http://localhost:8000/api/transfers -H 'Accept: application/json'`.

**Expected:** the documented `{"error":{"code","message"}}` shape with an
appropriate status.

**Actual:** (1) a full `<!DOCTYPE html>` error page from a JSON-only API,
because Laravel decides on JSON via `$request->expectsJson()` and a plain curl
sends `Accept: */*`. (2) 405 with
`{"message":"The GET method is not supported for route api/transfers...","exception":...}`
— right status, wrong shape.

**Cause.** `bootstrap/app.php` registers renderers for six specific exception
types. Nothing is registered for `NotFoundHttpException` (unknown route),
`MethodNotAllowedHttpException`, a generic `HttpException`, or an uncaught
`Throwable`, so those fall through to Laravel's default handler.

**Severity reasoning.** Medium: the happy paths and every business-rule error
are correct and consistent, and it is the edges that drift. But "consistent JSON
shape" is an explicit requirement, and an HTML page is the least useful possible
response to a machine client.

**Fix.** Add a catch-all `Throwable` renderer after the specific ones, or
`$exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'))`.


**Fixed.** `bootstrap/app.php` now calls `shouldRenderJsonWhen()` for anything
under `/api`, so a client that omits `Accept` still receives JSON rather than an
HTML page, and renderers were added for `MethodNotAllowedHttpException` (405
`method_not_allowed`), `ThrottleRequestsException` (429 `rate_limited`), a
catch-all `HttpExceptionInterface`, and a final `Throwable` that answers 500
`server_error` without leaking anything.
`AccountApiTest::test_an_unknown_route_answers_json_not_html` and
`test_a_wrong_http_method_answers_405_in_the_error_envelope` cover it.

---

### BUG-10 — `amount` has no upper bound, so a large deposit returns 500
**Severity: Medium.** *Found by reasoning about the integer range, **fixed late**.*

**Steps to reproduce**
1. `POST .../deposits` `{"amount":9223372036854775807}` → 201. The `bigint`
   column accepts it.
2. `POST .../deposits` `{"amount":1}` with a fresh key.

**Expected:** 422 in the error envelope, or a domain limit that rejects step 1.

**Actual:** 500. `Money::add` computes `PHP_INT_MAX + 1`, which PHP silently
promotes to a float, and the float then fails the `readonly int` constructor
parameter with a `TypeError`. The surrounding `DB::transaction` rolls back, so
no balance is corrupted — the defect is the dishonest status code, plus the fact
that the ledger accepted a deposit no real system would.

**Severity reasoning.** Medium: unreachable by accident and it corrupts nothing,
but "handles large amounts" was a claim I had made for this service and it turned
out to be only half true. The integer column does not overflow; the arithmetic in
front of it does.

**Fix.** A `max:` rule on every amount, plus an explicit overflow guard in
`Money::add` throwing `InvalidArgumentException` — which already renders as 422.


**Fixed.** `config/wallet.php` now carries `max_amount_minor`, a domain ceiling
of 10^12 minor units enforced on every amount, and `Money::add()` refuses an
operand that would push the total past `PHP_INT_MAX`. Both surface as a 422 in
the error envelope rather than a `TypeError` and a 500.
`MoneyTest::test_addition_beyond_the_integer_range_is_refused` covers the
arithmetic guard.

---

### BUG-11 — "Newest first" is not a deterministic order
**Severity: Medium.** *Found by reading the query against the column type,
**fixed late**.*

**Description.** `AccountController::transactions` orders by `created_at` alone,
and `transactions.created_at` is a MySQL `timestamp` with second precision.
Several transactions written in the same second share a sort key, so their
relative order is whatever the storage engine returns, and it need not be stable
between two queries.

**Expected:** a total order. **Actual:** a partial one. With offset pagination
this means a row can appear on two consecutive pages, or on neither, if the
order shifts between the two requests.

**Severity reasoning.** Medium: it misleads a human reading a statement and
breaks any client that pages through history, but no money moves and nothing is
lost. Hard to observe by hand, which is exactly why it is worth writing down —
the automated ordering test only passes reliably because it stamps its three
deposits a minute apart with `travelTo()`.

**Fixed.** The query now carries a tiebreaker:

```php
->orderByDesc('created_at')->orderByDesc('id')
```

`HasUuids` generates ordered UUIDs, so descending `id` is chronological and the
sort key is now total. Raising the column to `timestamp(6)` would narrow the
window of ties but would not remove the need for a tiebreaker, so it was not
worth a migration.

*Honest caveat:* this fix is reasoned from the id generation strategy, not
proven by a test. Writing a test that fails without the tiebreaker would mean
provoking a tie and then relying on the storage engine to return the rows in a
particular wrong order — which is exactly the non-determinism being fixed. The
existing ordering test still stamps its rows a minute apart with `travelTo()`.

---

### BUG-12 — The history response is shaped unlike every other endpoint, and leaks a column
**Severity: Low.** *Not fixed.*

**Steps to reproduce:** `GET /api/accounts/{id}/transactions`.

**Expected:** the same hand-shaped body style used by every other endpoint.

**Actual:** Laravel's raw paginator — `first_page_url`, `last_page_url`,
`next_page_url`, `prev_page_url`, `path`, `links[]` — and every row carries
`"idempotency_key":null`, a column that exists in the schema and in `$fillable`
but is never written by any code path.

**Severity reasoning.** Low: nothing breaks and nothing sensitive is exposed. It
is an API-design inconsistency — one endpoint speaks a different dialect from the
other five — and the always-null column advertises an unfinished feature.

**Fix.** Shape the response like the others, and either populate
`transactions.idempotency_key` (it would also serve as the ledger-level backstop
described in BUG-08) or drop the column.

---

### BUG-13 — `withdraw()` is the only money path that skips the frozen check
**Severity: Low.** *Found by comparing the three money paths, **fixed late**.*

**Description.** `WalletService::deposit()` and `transfer()` both call
`assertActive()`. `withdraw()` does not, so a frozen account could still be
debited.

**Severity reasoning.** Low, and only because it is currently unreachable: no
endpoint, factory or seeder can set `accounts.status` to `frozen`, so the whole
feature is dead code today. It is recorded because the asymmetry is a trap — the
first person to add an admin freeze endpoint inherits a hole that looks closed.
The frozen-account feature is also outside the specification and would be better
removed than completed.


**Fixed.** `withdraw()` now calls `assertActive()` like `deposit()` and
`transfer()`, so the three money paths carry the same guard set. The feature is
still unreachable over the API - no endpoint sets `accounts.status` to
`frozen`, only `AccountFactory::frozen()`, which no test uses - so this closes
the inconsistency rather than completing the feature. The frozen-account state
is outside the specification and would still be better removed than finished.

---

### BUG-14 — `POST /accounts` rejects the payload the specification defines
**Severity: Medium.** *Not fixed — a deliberate deviation, recorded here so it
is not mistaken for an oversight.*

**Steps to reproduce:** `POST /api/accounts` with
`{"owner_name":"Spec Reviewer","currency":"USD"}`.

**Expected, per section 2 of the specification:** 201, a new account with a zero
balance.

**Actual:** 422, reporting `first_name`, `last_name`, `email`, `phone` and
`password` as required.

**Cause.** The owner name was split into `first_name`/`last_name`, and three
authentication fields were made mandatory when the out-of-scope authentication
extension was added.

**Severity reasoning.** Medium, and arguably higher in grading terms than in
production terms: nothing is corrupted, but a reviewer following the
specification cannot create an account on the first try. The `owner_name` value
is still returned on every account response as a computed accessor. See the
README for the full list of deviations.

---

## 5. Questions and assumptions

The specification is deliberately incomplete. These are the points where I had
to choose, what I chose, and why.

**Q1. "Create account takes an owner name and a 3-letter currency code." Is a
single free-text owner name intended, or structured fields?**
*Assumption:* structured — `first_name` and `last_name`, with `owner_name`
returned as a computed accessor so the documented field still appears in every
response. *Why:* splitting is trivially reversible; joining is not.
*Cost, honestly:* combined with the authentication extension this means the
specification's own example payload is rejected (BUG-14). Given the time again I
would have kept `owner_name` as the wire format and split it internally, if at
all.

**Q2. Authentication is listed as out of scope. I built it anyway. Was that a
mistake?**
*Assumption at the time:* an ownership boundary would read as initiative.
*Conclusion now:* it was a mistake, and I would rather say so than defend it. It
cost time the specification told me not to spend; it made five of the six
required endpoints answer 401 to a spec-conformant client; its unique
email/phone columns mean one owner cannot hold both a USD and a EUR wallet; and
it introduced BUG-07, the most serious defect in the service. With one hour left
I would delete it rather than finish it.

**Q3. The specification's paths are `/accounts` and `/transfers`. Should they be
at the root?**
*Assumption:* Laravel's conventional `/api` prefix is kept, so the real paths are
`/api/accounts` and so on. *Why:* it is the framework default and changing it
buys nothing. *Cost:* a reviewer curling the literal path gets a 404, which is
why the base path is stated at the top of the README.

**Q4. "Define a sane default and maximum page size." What are they?**
*Assumption:* default 20, maximum 100. *Why:* a statement page a human reads is
around 20 rows; 100 bounds the worst-case response without needing a cursor.
*Was a problem, now fixed:* the values were declared in `config/wallet.php` but
the controller hardcoded them, so the config was dead — and the bounds were not
enforced on the low side at all, which is BUG-03. The controller now reads both
values from the config and clamps on both sides.

**Q5. Should a request that fails consume its idempotency key?**
*Assumption as built:* no — only 2xx responses are recorded. *Why this is
probably wrong:* rule 4 says a key reused with a *different payload* must
conflict, and that guarantee cannot hold for a key the service has forgotten
(BUG-06). I would record every terminal outcome and mark failures replayable.

**Q6. How long should idempotency keys be retained?**
*Assumption:* forever; there is no expiry and no cleanup job. *Why:* the spec is
silent and unbounded retention is the safe direction — a key forgotten too early
is a double-apply. *Trade-off:* the table grows without limit. A production
service would keep them 24–72 hours and reject older keys explicitly rather than
silently reusing them.

**Q7. Is transferring to your own account an error or a no-op?**
*Assumption:* an error, 422. *Why:* it is far more likely to be a client bug than
an intention, and a silent no-op would hide it.

**Q8. Is a zero-amount deposit or withdrawal valid?**
*Assumption:* no — `min:1`, so 0 is a 422. *Why:* a zero-value ledger row carries
no information and only adds noise to a statement. A system that needs "touch"
records should use an explicit type rather than a zero amount.

**Q9. "A 3-letter currency code" — any three letters, or a fixed list?**
*Assumption:* a whitelist — `USD, IQD, EUR, GBP` — in `config/wallet.php`.
*Why:* accepting `ZZZ` creates accounts nobody can ever transact with, and the
same-currency transfer rule already makes a lone currency a dead end. *Cost:* a
spec-conformant `{"currency":"JPY"}` is rejected; the error message names the
accepted codes so the client is not left guessing.

**Q10. Minor units differ per currency — USD has 2 decimals, IQD has 0. Where is
the exponent?**
*Assumption:* nowhere. Every amount is an opaque integer of minor units and the
service never formats or converts. *Why:* the spec forbids conversion and asks
only that money be integer minor units. *Risk I am flagging:* `1000` means
$10.00 in USD and 1000 IQD, and nothing in the schema records which. The moment
anything renders a balance this becomes a real problem, and the exponent should
live next to the currency code.

**Q11. Should the transaction ledger record the currency?**
*Assumption:* no — it is derivable from the account. *Why:* an account's currency
is immutable today. *Risk:* if a currency change is ever permitted, every
historic row silently re-denominates. A ledger that is not self-describing is a
liability; I would add the currency and the minor-unit exponent to `transactions`
before that becomes a real feature.

**Q12. Deposits, withdrawals and transfers create a record. Should they return
201 or 200?**
*Assumption:* 201, with the created transaction as the body. *Why:* each creates
a durable ledger resource. *Consistency note:* a replayed idempotent request also
returns 201 with the original body, which is deliberate — rule 4 says to return
the original result, and changing the status on replay would make the response
depend on delivery history.

**Q13. What is the maximum amount the service should accept?**
*Assumption:* none was set, which turned out to be wrong (BUG-10). A real answer
needs a product decision; a defensible default is 10^15 minor units, comfortably
inside `bigint` and outside any legitimate transaction.

**Q14. Should a rejected request consume anything observable — a rate limit, an
audit row?**
*Assumption as first built:* no. Nothing was recorded for a failure and there
was no rate limiting anywhere, which left the login endpoint the auth extension
added as an unthrottled credential-stuffing target.
*What changed:* both halves were closed. Failed attempts are now recorded by the
idempotency layer (BUG-06), and four named limiters exist — login (per
credential+IP and per IP, counting only 401s), registration, money operations
(per account and per IP), and authenticated POSTs — with the bounds in
`config/wallet.php` and a 429 `rate_limited` response in the standard envelope.
*Honesty about scope:* the specification did not ask for rate limiting, and it
is listed nowhere in section 2, 3 or 4. It is a deliberate addition, not credit
being claimed. Its one effect on testing is that the suite must not trip its own
limits; `phpunit.xml` sets `CACHE_STORE=array` and Laravel rebuilds the
container per test, so limiter counters reset between tests and the bounds (3
registrations and 10 money operations per minute) are never approached inside a
single test.
*What is still not done:* there is no audit trail of rejected requests beyond
the idempotency record — no structured log of who was throttled, and no alerting.
A real deployment would want both.

---

## 6. What was fixed, and what I would do next

### Fixed before submission

Twelve of the fourteen defects were found — by the tests, by the manual session,
or by reading the code against its own claims — and then closed. The reports
above are kept in full rather than deleted, because how a defect was found is
more informative than the diff that closed it.

| ID | Fix | Now covered by |
|---|---|---|
| BUG-04, BUG-05 | The idempotency fingerprint covers method + path + body, not the body alone | 2 tests asserting 409 |
| BUG-07 | An ownership guard on every account route and on `from_account_id`, rendered as `403 forbidden` | 4 tests |
| BUG-08 | A reservation row written before the operation, inside the same transaction, so a concurrent duplicate loses on the unique index | reasoned; the surrounding behaviour is tested, the race itself is not |
| BUG-06 | Every terminal outcome below 500 recorded, so a failure replays and a changed payload conflicts | 1 test walking 422 → recorded → replayed → 409 |
| BUG-03 | `per_page` clamped on both sides, reading the bounds from `config/wallet.php` | 2 tests |
| BUG-02, BUG-09 | Renderers retyped onto the exceptions Laravel actually raises, plus JSON forced for `/api/*` and a catch-all `Throwable` | 3 tests |
| BUG-10, BUG-01 | A domain ceiling on amounts, an overflow guard in `Money::add`, and `add`/`subtract` routed through `of()` | 3 tests |
| BUG-11 | `orderByDesc('id')` as a tiebreaker, so "newest first" is a total order | reasoned, not tested — see the report |
| BUG-13 | `assertActive()` added to `withdraw()`, so all three money paths carry the same guards | not tested; the feature is unreachable over the API |

Rule 5 was also hardened while fixing BUG-13's neighbourhood: `Transaction` now
throws on `updating` and `deleting`, so the append-only ledger is enforced by the
model rather than only by convention.

### Still open

**BUG-12** — the history endpoint returns Laravel's raw paginator while every
other endpoint returns a hand-shaped body, and each row still carries an
always-null `idempotency_key` column. Cosmetic, and left deliberately: changing
the response shape this late would have meant rewriting assertions in four tests
for no behavioural gain.

**BUG-14** — `POST /accounts` does not accept the specification's payload. That
is a consequence of the out-of-scope authentication extension, not an oversight;
see Q1 and Q2.

### What I would do next, in this order

The order follows the risk, not the effort.

1. **Delete the authentication extension.** It was out of scope, it caused
   BUG-07, and it is why the specification's own create-account payload is
   rejected (BUG-14) and why one owner cannot hold two currencies. Fixing the
   authorization hole made the service safe; removing the layer would make it
   correct.
2. **Serve behind php-fpm and nginx**, then build the harness that drives
   concurrent requests through the full middleware stack — so BUG-08's race can
   be *proved* rather than reasoned about. It is the only fix in the table above
   that rests on an argument instead of a test, and that is the gap I would close
   first.
3. **Add `UNIQUE(account_id, idempotency_key)` on `transactions`** as a
   ledger-level backstop, and populate the column — it exists, is fillable, and
   is never written, which is half of BUG-12.
4. **Extract the balance rule out of `WalletService`** into a pure function over
   `(balanceMinor, amountMinor, currency)`, so rule 1 is unit-testable without a
   database as the specification asks. Today only `Money` meets that bar.
5. **Shape the history response like every other endpoint** and close BUG-12.
6. **Give idempotency keys a lifetime** — a retention window and an explicit
   rejection of keys older than it, instead of an unbounded table (Q6).
