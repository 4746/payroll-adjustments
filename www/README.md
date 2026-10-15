# Alcor Payroll Adjustments

A proof of concept for payroll specialists who need to correct automatically calculated salaries without losing the original calculation or the reason for each correction. The event stream is the authoritative record; the current amount and audit history are reconstructed from it.

## Architecture and key decisions

The design was recorded before implementation: one payroll line is one aggregate and one ordered event stream, Money uses exact integer cents, the first adjustment permanently freezes the system baseline, MySQL appends event batches atomically with optimistic concurrency, and read queries replay events without projection tables.

- **Domain — `app/Payroll/Domain`:** framework-independent PHP aggregate, immutable Money and domain events, invariant enforcement, event replay. No Laravel, Eloquent, HTTP, or database dependencies.
- **Application — `app/Payroll/Application`:** three state-changing use cases (`CreatePayrollLine`, `RecalculatePayrollLine`, `ApplyManualAdjustment`), two queries (`GetPayrollLineAmount`, `GetAdjustmentHistory`), immutable audit result, and the `EventStore` port.
- **Infrastructure — `app/Payroll/Infrastructure`:** Laravel query-builder MySQL event store, transaction handling, explicit versioned serialization.
- **Composition:** `AppServiceProvider` binds the port to the database implementation using constructor injection. Artisan provides a demonstration entry point.

DDD gives the financial rules a small testable boundary. Event Sourcing fits this particular audit requirement because the immutable sequence of decisions is itself valuable. CQRS-lite keeps changes separate from queries while sharing the same storage. These choices are appropriate for this PoC, rather than prerequisites for every payroll application. There is no command bus, asynchronous delivery, projection table, Eloquent aggregate, or external event-sourcing package.

Implementation followed Money → domain events and aggregate → replay → MySQL persistence → application use cases → CLI → verification. No application dependencies or Docker configuration were changed.

## Behavior and aggregate invariants

A payroll line starts with `PayrollLineCalculated`. Further automatic calculations replace its current amount until the first successful manual adjustment.

Each `ManualAdjustmentApplied` adds a signed amount and preserves its own mandatory UTF-8 comment. The first freezes the **latest** system-calculated amount. Subsequent automatic recalculations become no-ops: they change neither current amount nor baseline, and append no events. Compensating adjustments never remove this permanent restriction, even if their sum is zero.

The aggregate enforces:

- A calculation must precede every adjustment.
- After manual override: **current amount = frozen system amount + all adjustments in application order**.
- Adjustments are non-zero; positive and negative values are supported.
- Blank or whitespace-only comments, including Unicode whitespace, are rejected. Accepted comments are preserved exactly.
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

Queries load the ordered stream in one database read and return either Money or an immutable `AdjustmentHistory` containing the payroll line ID, current amount, nullable frozen baseline, and all adjustment events. Each adjustment retains its event ID, version, timestamp, delta, and original comment. This avoids inconsistent separately queried totals and history.

Application order is determined by **stream version**, not timestamps. UTC timestamps retain microseconds but can tie or move backwards if the system clock changes. Event IDs use 128 random bits represented as 32 lowercase hexadecimal characters; the database enforces uniqueness.

Immutable audit history differs from a mutable adjustments table: a mutable list can rewrite the past, remove a correction, or change its reason. Here the source of truth is an append-only sequence of facts. Compensation adds another visible fact, preserving what happened and why.

## Persistence and concurrency

Migration: `database/migrations/2026_10_15_151749_create_payroll_events_table.php`.

| Column | Purpose |
| --- | --- |
| `event_id CHAR(32)` | Global primary key; unique event identity |
| `aggregate_id VARCHAR(128)` | Case-sensitive payroll line ID |
| `version BIGINT UNSIGNED` | Contiguous aggregate sequence starting at 1 |
| `event_type VARCHAR(64)` | Explicit `payroll_line_calculated.v1` or `manual_adjustment_applied.v1` |
| `payload JSON` | Schema version, USD currency, exact cents, and adjustment comment |
| `occurred_at DATETIME(6)` | Event creation timestamp normalized to UTC |

The InnoDB table has a unique `(aggregate_id, version)` index, which also serves ordered stream reads. The event ID primary key and stream-version constraint prevent duplicate identities and lost updates. No mutable payroll balance table is maintained.

Payload example:

```json
{
    "schema_version":1,
    "currency":"USD",
    "amount_minor":"-4555",
    "comment":"Unpaid leave"
}
```

A command loads an aggregate at version N, applies a domain operation, and passes the unit of work to `EventStore::append()`. Inside one transaction the store compares N with the stored maximum version and inserts every pending event. A mismatched version raises `ConcurrencyConflict`. If another connection commits after the comparison, the unique stream-version constraint closes that race; its database exception is translated into the same application failure. Duplicate creation also conflicts.

Other write failures propagate, and the transaction rolls back the entire batch. Rejected domain operations never reach append. Ignored recalculations have no pending events and perform no writes. There is no automatic retry of business commands: a caller must reload and decide whether to reapply its intent.

An aggregate is a single unit of work: append it once, then discard it and reload for another operation. Append deliberately does not clear pending events, so failed writes can be retried without losing them; resubmitting an already successful batch conflicts. Application use cases follow this lifecycle. An outer transaction can include append, but callers must discard in-memory state after an outer rollback.

### Append-only scope

The application exposes only load and append. Event objects and query results are immutable, and there are no event update/delete application APIs. This is **application-level immutability**, not tamper-proof database enforcement. A privileged SQL client, migration rollback, or code bypassing the store can still change or drop data. Tests may clean up their isolated database directly.

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

## Assumptions, limitations, and production improvements

- This PoC focuses on one payroll line. It does not model employees, payroll periods, approval workflows, identities of payroll specialists, or multiple currencies.
- Replaying a whole stream costs O(number of events) in time and memory. This is reasonable for short payroll histories. Add snapshots or independently rebuildable projections only when measured volume justifies them.
- Event types and payloads are explicitly versioned; unknown schemas fail instead of guessing. Production event evolution needs migrations/upcasters and compatibility policies.
- Random event IDs provide traceability, not business-command idempotency. A caller must not blindly retry an adjustment after an uncertain network/commit outcome; production integrations should add explicit idempotency keys.
- Optimistic conflicts are surfaced, not automatically resolved. Deadlocks and operational database failures propagate; production callers need an appropriate retry policy and monitoring.
- Metadata includes event identity and creation time, but no actor, request correlation, or attempted recalculation audit. Ignored recalculations intentionally create no domain event.
- System time is used when events are created. Stream versions establish authoritative order.
- Immutability is enforced through the application boundary; privileged SQL access remains a limitation described above.
- The CLI's eight operations are separate use cases, not a single transaction covering the whole demonstration. An interrupted demo can leave a valid partial stream.
- There is no REST API, authentication, UI, queue, broker, or background projection worker.
