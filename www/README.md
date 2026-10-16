# Alcor Payroll Adjustments

A proof of concept for payroll specialists who need to correct automatically calculated salaries without losing the original calculation or the reason for each correction. The event stream is the authoritative record; current state is maintained alongside it for fast reads, while audit history and state rebuilds replay events.

## Architecture and key decisions

One payroll line is one aggregate and one ordered event stream. Money uses exact integer minor units, and the first adjustment permanently freezes the system baseline. MySQL commits events and current state together with optimistic concurrency. Normal reads and changes load current state; audit and rebuild operations replay the journal.

- **Domain — `app/Payroll/Domain`:** framework-independent PHP aggregate, immutable Money and domain events, currency and event-type enums, ID validators, decimal amount parser, invariant enforcement and event replay. No Laravel, Eloquent, HTTP, or database dependencies.
- **Application — `app/Payroll/Application`:** three state-changing use cases (`CreatePayrollLine`, `RecalculatePayrollLine`, `ApplyManualAdjustment`), three queries (`GetPayrollLineAmount`, `GetPayrollLines`, `GetAdjustmentHistory`), immutable audit result, and the `EventStore` port.
- **Infrastructure — `app/Payroll/Infrastructure`:** Laravel query-builder MySQL implementation of `EventStore`, current-state and journal persistence, atomic writes, optimistic concurrency, versioned serialization and state rebuilding.
- **HTTP — `app/Payroll/Http`:** `Controllers` invoke application use cases; `Requests` contain Laravel Form Request input validation; `Resources` format API responses with decimal amount strings and UTC timestamps. HTTP classes live inside the payroll module, and API versioning remains in `routes/api.php` rather than nested controller folders.
- **Composition and CLI:** `AppServiceProvider` binds `EventStore` to its MySQL implementation through constructor injection. `app/Console/Commands` contains the demonstration and current-state rebuild commands.

For an HTTP write, Laravel validates the Form Request, the controller calls an Application use case, and the use case invokes Domain behavior, loading existing state through `EventStore` when updating a payroll line. The Infrastructure implementation commits current state and pending events together; the controller then returns a resource. Current amounts and lists read `payrolls`; full audit reads `payroll_events`. Domain does not depend on the outer layers. HTTP may use domain types to validate input or present results, but payroll business rules remain enforced by Domain.

DDD gives the financial rules a small testable boundary. Event Sourcing preserves the authoritative sequence of facts. CQRS-lite separates changes from queries, with a synchronously maintained current-state table in the same database. There is no command bus, asynchronous delivery, Eloquent aggregate, or external event-sourcing package.

Implementation followed Money → domain events and aggregate → replay → MySQL persistence → application use cases → CLI → verification. No application dependencies or Docker configuration were changed.

## Behavior and aggregate invariants

A payroll line starts with `PayrollLineCalculated`. Further automatic calculations replace its current amount until the first successful manual adjustment.

Each `ManualAdjustmentApplied` adds a signed amount and preserves its own mandatory UTF-8 comment. The first freezes the **latest** system-calculated amount. Subsequent automatic recalculations become no-ops: they change neither current amount nor baseline, and append no events. Compensating adjustments never remove this permanent restriction, even if their sum is zero.

The aggregate enforces:

- A calculation must precede every adjustment.
- After manual override: **current amount = frozen system amount + all adjustments in application order**.
- Adjustments are non-zero; positive and negative values are supported.
- Blank or whitespace-only comments, including Unicode whitespace, are rejected. Accepted comments are preserved exactly.
- Adjustment comments are limited to **2000 Unicode characters**, counted as UTF-8 code points rather than bytes. The shared domain constant `ManualAdjustmentApplied::MAX_COMMENT_LENGTH` is enforced by both the domain (including event replay) and the HTTP Form Request. Exactly 2000 characters is accepted; 2001 is rejected without changing state or appending an event. HTTP rejects an overlong comment with a 422 field-validation error.
- Failed validation or arithmetic overflow leaves the aggregate and its pending events unchanged.
- There are no edit, delete, reset, or unfreeze operations. Correct a mistake with a new compensating adjustment.
- Replayed events must have contiguous versions starting at 1, matching aggregate IDs, unique event IDs, and valid domain transitions.

Zero-valued adjustments are rejected because they do not change pay and should not silently activate manual override. Zero and negative automatic totals, and negative final totals, are allowed: the assignment imposes no minimum-pay rule. IDs contain 1–128 ASCII letters, digits, underscores, or hyphens and are case-sensitive.

Before the first adjustment, the frozen baseline is `null`. Earlier automatic calculations remain in the event stream even though only the latest calculation determines the baseline.

## Financial accuracy

USD is the only currency. A 64-bit PHP runtime is required. `Money` stores signed integer cents; no float arithmetic, casts to floats, or rounding are used.

```php
Money::fromDecimal('1000.00');
Money::fromDecimal('-45.55');
Money::fromMinorUnits(10)->add(Money::fromMinorUnits(20))->toDecimal(); // '0.30'
```

Decimal input accepts an optional sign, an integer part, and up to two fractional digits. It rejects whitespace, thousands separators, scientific notation, leading zeroes, fractional cents, and out-of-range input. Output always has two fractional digits. Addition and subtraction explicitly detect overflow.

Supported minor units are `PHP_INT_MIN` through `PHP_INT_MAX`, equivalent to USD `-92233720368547758.08` through `92233720368547758.07` on 64-bit PHP. Intermediate operation results must also fit. Event JSON encodes cents as canonical integer **strings** to avoid precision loss in JSON consumers.

## Event replay and audit history

`PayrollLine::reconstitute($id, $events)` applies the same state transitions without creating new pending events. The initial and later automatic calculations establish the system amount; the first adjustment freezes it, and each adjustment adds its delta. Invalid or unknown history fails closed.

Current amount queries and state-changing operations load one row from `payrolls`. List queries read current states in one query without loading event history. Audit queries replay the ordered journal and return an immutable `AdjustmentHistory` containing the payroll line ID, current amount, nullable frozen baseline, and all adjustment events with their IDs, versions, timestamps, deltas and original comments.

Application order is determined by **stream version**, not timestamps. UTC timestamps retain microseconds but can tie or move backwards if the system clock changes. Event IDs use 128 random bits represented as 32 lowercase hexadecimal characters; the database enforces uniqueness.

Immutable audit history differs from a mutable adjustments table: a mutable list can rewrite the past, remove a correction, or change its reason. Here the source of truth is an append-only sequence of facts. Compensation adds another visible fact, preserving what happened and why.

## Persistence and concurrency

Both tables use InnoDB. Events are the authoritative history; current state is updated synchronously and can be rebuilt from events.

### `payrolls` — current state

Migration: `database/migrations/2026_10_15_111749_create_payrolls_table.php`.

| Column | Purpose |
| --- | --- |
| `id VARCHAR(128)` | Primary key; one current-state row per payroll line |
| `current_amount_minor BIGINT` | Current amount in signed integer minor units |
| `currency CHAR(3)` | Currency fixed for the payroll line |
| `frozen_system_amount_minor BIGINT NULL` | Frozen system baseline; `NULL` means not frozen, while zero is a valid frozen amount |
| `version BIGINT UNSIGNED` | Last applied event version; used for optimistic concurrency |
| `updated_at DATETIME(6)` | UTC time when state was written |

### `payroll_events` — immutable journal

Migration: `database/migrations/2026_10_15_151749_create_payroll_events_table.php`.

| Column | Purpose |
| --- | --- |
| `event_id CHAR(32)` | Global primary key; unique event identity |
| `payroll_id VARCHAR(128)` | Payroll line ID matching `payrolls.id` |
| `version BIGINT UNSIGNED` | Contiguous aggregate sequence starting at 1 |
| `event_type VARCHAR(64)` | Enum-backed `calculated.v1` or `manual_adjustment_applied.v1` |
| `payload JSON` | Schema version, USD currency, exact cents, and adjustment comment |
| `occurred_at DATETIME(6)` | Event creation timestamp normalized to UTC |

The journal has a unique `(payroll_id, version)` index, which also serves ordered stream reads. Its `event_id` primary key prevents duplicate event identities.

The relationship is one payroll line to many events: `payrolls.id = payroll_events.payroll_id`. This is a logical relationship; the migrations do not declare a foreign key. State IDs, journal payroll IDs and event IDs use `ascii_bin` for case-sensitive comparisons; state currency uses the same collation. `payrolls` has only its primary-key index. Full history is read only for audit or rebuild, so normal reads and changes do not need a separate periodic snapshot table.

Payload example:

```json
{
    "schema_version":1,
    "currency":"USD",
    "amount_minor":"-4555",
    "comment":"Unpaid leave"
}
```

A command loads current state at version N, applies a domain operation, and passes the unit of work to `EventStore::append()`. Inside one transaction the store updates `payrolls` only where the stored version still equals N, then inserts every pending event. If no state row matches, it raises `ConcurrencyConflict`. Creation inserts a new state row; duplicate state IDs, event IDs or stream versions also produce a conflict. If an event write fails, the state change rolls back with it.

Other write failures propagate, and the transaction rolls back the entire batch. Rejected domain operations never reach append. Ignored recalculations have no pending events and perform no writes. There is no automatic retry of business commands: a caller must reload and decide whether to reapply its intent.

An aggregate is a single unit of work: append it once, then discard it and reload for another operation. Append deliberately does not clear pending events, so failed writes can be retried without losing them; resubmitting an already successful batch conflicts. Application use cases follow this lifecycle. An outer transaction can include append, but callers must discard in-memory state after an outer rollback.

### Append-only scope

There are no event update/delete application APIs. Current-state updates and rebuilds change `payrolls`, while the journal remains append-only through the application. Event objects and audit results are immutable. This is **application-level immutability**, not tamper-proof database enforcement. A privileged SQL client, migration rollback, or code bypassing the store can still change or drop data. Tests may clean up their isolated database directly.

A production deployment should separate migration credentials from runtime credentials, grant the runtime user only SELECT/INSERT on the journal, protect backups, and consider external tamper-evident audit storage. The migration's `down()` drops audit history and must not be used casually on real payroll data.

## Requirements and existing Docker environment

Verified installed versions: PHP 8.4.26, Laravel 13.36.0, PHPUnit 12.5.39, Pint 1.32.1, and the existing MySQL 8.4.7 service. Composer dependencies are unchanged. The existing compose file is `../compose.yml`; the application is mounted at `/var/www`.

Run commands from this directory or its parent (Compose discovers the existing file). Start the already configured environment if necessary:

```bash
docker compose up -d
docker compose exec payroll_adjustments_cloud bash
```

Inside the container:

```bash
php --version
composer show --direct
```

Use the existing installed dependencies; on a fresh checkout with no `vendor`, run `composer install` inside the same container. No frontend build is needed for this assignment.

For a development demo, configure the existing `.env` to use MySQL:

```dotenv
DB_CONNECTION=mysql
DB_HOST=payroll_adjustments_cloud_db
DB_PORT=3306
DB_DATABASE=payroll_adjustments_cloud_local
DB_USERNAME=mysql
DB_PASSWORD=mysql
```

These are the existing Compose development credentials. Do not reuse them in production. Retain the application's existing APP_KEY and other settings.

Apply migrations, without dropping existing tables:

```bash
docker compose exec -T payroll_adjustments_cloud php artisan config:clear --no-interaction
docker compose exec -T payroll_adjustments_cloud php artisan migrate --no-interaction
```

If working with cached configuration, clear it after changing database settings.

## Isolated MySQL test database

Integration tests use a dedicated `payroll_testing` connection and the fixed database name **`payroll_adjustments_test`**. They never use the default development database. Create this database and its restricted user in the existing MySQL container:

```bash
docker compose exec -T payroll_adjustments_cloud_db mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS payroll_adjustments_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'payroll_test'@'%' IDENTIFIED BY 'payroll_test'; GRANT ALL PRIVILEGES ON payroll_adjustments_test.* TO 'payroll_test'@'%';"
```

The root password above comes from the existing local Compose configuration. The test user has privileges only on the test schema. MySQL may print a warning about command-line passwords; use an interactive `-p` prompt if preferred.

The test connection defaults to the existing database service, port 3306, and `payroll_test/payroll_test`. Override `PAYROLL_TEST_DB_HOST`, `PAYROLL_TEST_DB_PORT`, `PAYROLL_TEST_DB_USERNAME`, or `PAYROLL_TEST_DB_PASSWORD` through the environment when needed. Its schema name is intentionally fixed.

PHPUnit forces the isolated connection. The Laravel base test case refuses cached configuration, an unexpected connection, driver, or database **before** database-refresh traits run. `LazilyRefreshDatabase` migrates and resets only the test schema, then rolls test data back. The committed two-connection race test explicitly cleans its record using its separate connection. Do not store valuable data in this disposable schema or run multiple suites against it concurrently.

Run from the host:

```bash
# Pure PHP unit tests: no Laravel application boot or database required
docker compose exec -T payroll_adjustments_cloud vendor/bin/phpunit --testsuite Unit

# Integration tests against the actual MySQL service
docker compose exec -T payroll_adjustments_cloud php artisan test --compact --testsuite Feature

# Entire existing and added suite
docker compose exec -T payroll_adjustments_cloud php artisan test --compact
```

Integration tests cover ordered persistence and replay, exact metadata, batch transactions, runtime and real MySQL write failures, stale writers, duplicate creation, the unique-constraint race, two independent connections with a competing commit, dependency injection, rejected operations, isolated streams, and the full scenario. CLI tests verify displayed amounts and duplicate/invalid IDs.

The repository's formatter is Pint:

```bash
docker compose exec -T payroll_adjustments_cloud vendor/bin/pint --dirty --format agent
```

In this Compose layout, `.git` is outside the mounted `www`, so `--dirty` is unavailable inside the container. Use the explicit changed paths instead:

```bash
docker compose exec -T payroll_adjustments_cloud vendor/bin/pint app/Payroll app/Console/Commands/PayrollDemo.php app/Providers/AppServiceProvider.php config/database.php database/migrations/2026_10_15_151749_create_payroll_events_table.php tests/Unit/Payroll tests/Feature/Payroll tests/TestCase.php --format agent
```

No additional static-analysis tooling was introduced.

## Run the example scenario

With development MySQL configured and migrations applied:

```bash
docker compose exec -T payroll_adjustments_cloud php artisan payroll:demo
```

The command creates a new randomly identified payroll line and persists every accepted operation. It prints each amount and a final table with event IDs, versions, UTC timestamps, and comments. You can supply `--id=my-example`; reusing an existing ID returns a conflict and leaves history intact. Each command invocation adds persistent demo data to the selected database.

To run exclusively in the disposable test database, without changing development settings:

```bash
docker compose exec -T payroll_adjustments_cloud php artisan migrate --database=payroll_testing --no-interaction
docker compose exec -T -e DB_CONNECTION=payroll_testing payroll_adjustments_cloud php artisan payroll:demo
```

| Step | Operation | Current USD |
| --- | --- | --- |
| 1 | Create | 1000.00 |
| 2 | Recalculate | 1050.00 |
| 3 | -45.55, Unpaid leave | 1004.45 |
| 4 | Recalculate to 1200.00 (ignored) | 1004.45 |
| 5 | +100.10, Bonus | 1104.55 |
| 6 | -0.10, Correction A | 1104.45 |
| 7 | -0.20, Correction B | 1104.25 |
| 8 | +0.20, Reverse correction B | 1104.45 |

The final frozen baseline is **1050.00**, with exactly **five** adjustment records and **seven** total events: two calculations plus five adjustments. Every comment survives persistence and reconstruction. The final amount is **1104.45**.

## Rebuild current state — `payroll:rebuild-states`

Reconstruct `payrolls` from `payroll_events` when state rows are missing or need to be regenerated:

```bash
# Every payroll ID present in the journal
docker compose exec -T payroll_adjustments_cloud php artisan payroll:rebuild-states --no-interaction

# A single payroll line
docker compose exec -T payroll_adjustments_cloud php artisan payroll:rebuild-states --id=my-example --no-interaction
```

The command replays the selected history and creates or updates each state row, including amount, currency, frozen baseline and version. It does not modify events. Each payroll line is rebuilt in a separate transaction with state and journal locks; earlier successful rebuilds remain committed if a later one fails. Invalid history or a requested ID without events returns a failure. State rows with no journal history are not removed. The command reports the number of rebuilt states and operates on the configured database.

## Manual HTTP testing

An HTTP adapter is available for local testing of the existing use cases.
Open [`http.yaml`](http.yaml) in an OpenAPI request interface. Each operation
declares editable parameter fields with demo defaults: creation has `id` and
`amount`; recalculation has a path `id` and `amount`; adjustment has a path `id`,
`amount` and `comment`. Both read operations expose their own path `id` field.
Change the field values before sending; no JSON body editing is required.
POST examples send input as query parameters. Existing JSON clients remain
supported. Use a new ID for each full scenario and the same ID for all its steps.

The specification also contains response schemas, error statuses and all eight
scenario writes in `x-business-scenario`, followed by the two read requests.

The existing Docker web server serves `http://localhost`; configure development
MySQL and run migrations as described above before sending requests. All routes
are under `/api/v1/payroll-lines`:

| Method | Path | Input / result |
| --- | --- | --- |
| POST | `/api/v1/payroll-lines` | `{"id":"http-example-1","amount":"1000.00"}`; 201 audit snapshot |
| POST | `/api/v1/payroll-lines/{id}/recalculations` | `{"amount":"1050.00"}`; 200 audit snapshot |
| POST | `/api/v1/payroll-lines/{id}/adjustments` | `{"amount":"-45.55","comment":"Unpaid leave"}`; 201 audit snapshot |
| GET | `/api/v1/payroll-lines/{id}` | 200 current amount |
| GET | `/api/v1/payroll-lines/{id}/adjustments` | 200 frozen baseline and full adjustment history |

Use `Accept: application/json`. The OpenAPI form sends the fields above as query
parameters. JSON clients can send the same fields in a body with
`Content-Type: application/json`; monetary JSON values must be **strings**,
including negative values. Responses also use decimal strings and wrap results
in `data`. Comments retain their original whitespace.
The first adjustment freezes the baseline; later recalculations return 200 with
unchanged state and append no event.

Use the same ID throughout a scenario and a new ID for each repetition. Existing
IDs return 409 on creation. Successfully repeating an adjustment applies it
again; requests have no idempotency key. Input/domain failures return 422,
missing lines return 404, and optimistic concurrency conflicts return 409.
There are no endpoints to edit or delete audit events.

In a database client, connect to the existing MySQL service on host port `33067`,
database `payroll_adjustments_cloud_local`, using the Compose development
credentials. Inspect `payroll_events` using your request's ID:

```sql
SELECT payroll_id, version, event_id, event_type, payload, occurred_at
FROM payroll_events
WHERE payroll_id = 'http-example-1'
ORDER BY version;
```

After all eight operations there are seven event rows, including five manual
adjustments. Reads do not create rows. HTTP feature tests run separately against
the protected `payroll_adjustments_test` schema:

```bash
docker compose exec -T payroll_adjustments_cloud php artisan test --compact tests/Feature/Payroll/PayrollLineHttpTest.php
```

This interface has no authentication and is intended for local PoC testing.
Production exposure requires access control.

## Assumptions, limitations, and production improvements

- The 2000-character adjustment-comment limit is an explicit implementation assumption agreed for this PoC; the assignment itself specifies a mandatory explanation but no maximum length.
- This PoC focuses on one payroll line. It does not model employees, payroll periods, approval workflows, identities of payroll specialists, or multiple currencies.
- Full audit and rebuild operations cost O(number of events) in time and memory. Normal reads and state-changing operations load `payrolls` without replaying history; list queries read a page in one query. Full audit history is currently not paginated.
- Event types and payloads are explicitly versioned; unknown schemas fail instead of guessing. Production event evolution needs migrations/upcasters and compatibility policies.
- Random event IDs provide traceability, not business-command idempotency. A caller must not blindly retry an adjustment after an uncertain network/commit outcome; production integrations should add explicit idempotency keys.
- Optimistic conflicts are surfaced, not automatically resolved. Deadlocks and operational database failures propagate; production callers need an appropriate retry policy and monitoring.
- Metadata includes event identity and creation time, but no actor, request correlation, or attempted recalculation audit. Ignored recalculations intentionally create no domain event.
- System time is used when events are created. Stream versions establish authoritative order.
- Immutability is enforced through the application boundary; privileged SQL access remains a limitation described above.
- The CLI's eight operations are separate use cases, not a single transaction covering the whole demonstration. An interrupted demo can leave a valid partial stream.
- The local HTTP adapter has no authentication. There is no UI, queue, broker, or background projection worker.
