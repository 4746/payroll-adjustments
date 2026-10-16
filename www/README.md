# Alcor Payroll Adjustments

A PHP proof of concept for manual corrections to an automatically calculated payroll line, preserving every adjustment and its reason.

## Business rules

- Automatic calculations replace the amount until the first manual adjustment.
- The first adjustment permanently freezes the latest system amount. Later recalculations do nothing, even after compensating adjustments sum to zero.
- Current amount = frozen system amount + all manual adjustments. Before manual override, it is the latest automatic amount.
- Adjustments are signed, non-zero amounts with a non-blank UTF-8 comment. Mistakes are corrected by adding a compensating adjustment; saved events have no edit/delete operations.
- Comments retain their original text and are limited to **2000 Unicode code points**, not bytes. This is an implementation assumption beyond the assignment, enforced in Domain and HTTP through one shared constant.
- Zero or negative totals are allowed. Invalid operations and overflow leave state and pending events unchanged.

## Architecture

One payroll line is one aggregate and one ordered event stream. DDD keeps rules independent of Laravel; Event Sourcing preserves audit facts; CQRS-lite separates changes from queries. No event-sourcing package, command bus or asynchronous processing is required.

| Folder | Responsibility |
| --- | --- |
| `app/Payroll/Domain` | Aggregate, immutable Money/events, enums, ID validators, decimal parser and business invariants |
| `app/Payroll/Application` | Use cases and separate `PayrollRepository`, `PayrollQueries`, `PayrollStateRebuilder` contracts |
| `app/Payroll/Infrastructure` | MySQL current state and journal, versioned serialization, transactions and rebuilding |
| `app/Payroll/Http` | Controllers, Form Request validation and API resources; API versioning is in `routes/api.php` |

HTTP writes pass through Form Request → controller → Application → Domain → `PayrollRepository` → response resource. Reads use `PayrollQueries`; CLI recovery uses `PayrollStateRebuilder`. The module's service provider binds these contracts to separate MySQL implementations. They share state mapping and event replay within Infrastructure; Domain has no Laravel or database dependencies.

### Storage and consistency

| Table | Contents |
| --- | --- |
| `payrolls` | One current-state row: `id`, `current_amount_minor`, `currency`, nullable `frozen_system_amount_minor`, `version`, `updated_at` |
| `payroll_events` | Append-only facts: `event_id`, `payroll_id`, `version`, `event_type`, JSON `payload`, `occurred_at` |

Migrations are in [`database/migrations`](database/migrations). The logical relationship is `payrolls.id = payroll_events.payroll_id`; no foreign key is declared. IDs use case-sensitive `ascii_bin`; journal `(payroll_id, version)` is unique and `event_id` is its primary key. A null frozen baseline means automatic recalculation is still allowed; a frozen zero is valid.

Normal reads and changes load current state, and lists use one query without event history. A transaction updates state only if its version matches the loaded version, then inserts pending events. Any failure rolls both back; competing writes return a conflict. Reload before the next operation; there is no automatic command retry.

The aggregate exposes current state and pending events, not an adjustment-history list. Full audit is obtained only through `PayrollQueries::history()`; write responses select new adjustments from pending events. Audit and rebuild replay events in version order, validating identities, transitions and payloads. Event types are `calculated.v1` and `manual_adjustment_applied.v1`; unknown formats fail. Each adjustment retains its ID, version, UTC timestamp, amount and comment.

### Money

`Money` carries a currency and signed 64-bit integer minor units. Input/output use decimal strings with a dot and at most two fractional digits; no floating-point arithmetic or rounding is used. Whitespace, commas, thousands separators, scientific notation, leading zeroes and overflow are rejected. JSON event amounts use integer strings to preserve precision.

Only USD is enabled. Currency is chosen on creation (default USD) and inherited by later operations. Commented enum cases are examples; currencies with zero or three fractional digits require currency-specific parsing and formatting before enabling them.

## Run locally

The existing [`../compose.yml`](../compose.yml) provides PHP 8.4, MySQL 8.4 and the `payroll_adjustments_cloud` application service. The app uses Laravel 13 and PHPUnit 12; dependencies are declared in `composer.json`.

```bash
docker compose up -d
# On a fresh checkout without vendor:
docker compose exec -T payroll_adjustments_cloud composer install
```

Copy [`.env.example`](.env.example) to `.env` if it does not exist; the project's Composer setup/create-project scripts also copy it when missing. Local MySQL connection details are already in the template. Set `DB_CONNECTION=mysql` in `.env`: that setting is currently commented out in the template, and the application otherwise defaults to SQLite. Preserve an existing application key; for a new environment, generate one with `php artisan key:generate`. Then apply migrations:

```bash
docker compose exec -T payroll_adjustments_cloud php artisan config:clear --no-interaction
docker compose exec -T payroll_adjustments_cloud php artisan migrate --no-interaction
```

## CLI commands

```bash
# Persist the assignment's eight-step scenario; optional --id=my-example
docker compose exec -T payroll_adjustments_cloud php artisan payroll:demo

# Rebuild all current states from history, or a single line with --id=my-example
docker compose exec -T payroll_adjustments_cloud php artisan payroll:rebuild-states --no-interaction
```

The demo writes real data to the configured database and prints each step and its audit. It generates a new ID by default; an existing ID conflicts. Expected amounts: **1000 → 1050 → 1004.45 → 1004.45 → 1104.55 → 1104.45 → 1104.25 → 1104.45**. Final frozen baseline: **1050.00**; five adjustments and seven events. Each operation commits separately.

Rebuild creates or updates `payrolls` without changing events. It processes journal IDs in separate transactions, locking each state and stream; a later failure does not undo earlier rebuilds. Invalid history or a requested ID without events fails. Orphan state rows are not removed.

## Tests and formatting

Unit tests need no database. Integration tests use the isolated `payroll_testing` connection and fixed database `payroll_adjustments_test`, never the development schema. On first setup, create its database and restricted user using the existing local MySQL credentials:

```bash
docker compose exec -T payroll_adjustments_cloud_db mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS payroll_adjustments_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'payroll_test'@'%' IDENTIFIED BY 'payroll_test'; GRANT ALL PRIVILEGES ON payroll_adjustments_test.* TO 'payroll_test'@'%';"
```

The connection defaults to `payroll_test/payroll_test`; host, port and credentials can be overridden with `PAYROLL_TEST_DB_*`. Tests refuse an unexpected database or cached configuration before refreshing the schema. Keep this database disposable and do not run database suites against it concurrently.

```bash
# Unit tests only
docker compose exec -T payroll_adjustments_cloud vendor/bin/phpunit --testsuite Unit
# Full suite, including MySQL, CLI and HTTP
docker compose exec -T payroll_adjustments_cloud php artisan test --compact
# Format explicit changed paths; --dirty is unavailable because .git is outside the mount
docker compose exec -T payroll_adjustments_cloud vendor/bin/pint app/Payroll app/Console/Commands tests/Unit/Payroll tests/Feature/Payroll --format agent
```

Tests cover the complete assignment scenario, compensation and permanent override after reload, Unicode comments and the 2000-character boundary, exact arithmetic, invalid history, atomic rollback, concurrent writers, rebuilding and lists without journal reads.

## HTTP API

Sequential request examples are in [`http.http`](http.http). Routes are defined in [`routes/api.php`](routes/api.php).

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/api/v1/payroll-lines` | Create with `id`, string `amount`, optional `currency` |
| POST | `/api/v1/payroll-lines/{id}/recalculations` | Recalculate with string `amount` |
| POST | `/api/v1/payroll-lines/{id}/adjustments` | Adjust with string `amount` and `comment` |
| GET | `/api/v1/payroll-lines/{id}` | Current amount and currency |
| GET | `/api/v1/payroll-lines/{id}/adjustments` | Full adjustment audit and frozen baseline |
| GET | `/api/v1/payroll-lines` | Current-state list; `page` and `per_page` |

Send `Accept: application/json`; JSON input uses `Content-Type: application/json` and **string amounts**. Responses wrap results in `data`. Writes return current state and only that operation's new adjustments; creation/recalculation return an empty adjustment list. Currency cannot be supplied as a non-empty field on subsequent changes.

Lists return `data` plus `meta.page`, `meta.per_page`, `meta.has_more`. Defaults: page 1, 100 items; allowed pages 1–1,000,000 and page sizes 1–100. Items are ordered by ID; an extra row detects the next page without counting all rows. Full audit is separate and currently unpaginated.

Create/adjust return 201; reads/recalculations return 200. Invalid input returns 422, missing lines 404, conflicts 409. There is no authentication; this adapter is for local PoC use.

## Scope and limitations

- A payroll line is not an employee balance. Employees, payroll periods, approval workflows and actor identities are outside this model.
- Payroll IDs allow 1–128 ASCII letters, digits, underscores or hyphens. This format, the comment limit and rejection of zero adjustments are explicit assumptions.
- Journal immutability is enforced by application APIs, not against privileged SQL access. Production needs appropriate database permissions, access control and backups.
- Requests have no idempotency keys: repeating an adjustment applies it again. After an uncertain commit, do not blindly retry. Production also needs a retry policy for concurrency/deadlocks and an event-schema evolution strategy.
- Full audit and rebuild use time and memory proportional to history length. Ignored recalculations create no audit event. Stream versions determine order; timestamps are traceability metadata.

## Possible next steps

- **Idempotent adjustments:** accept a client-generated operation key and atomically
  store its request parameters and result with the adjustment. Retries should return
  the original result without applying another adjustment, including concurrent retries.
  Reject the same key with different parameters.

- **Audit actors and access control:** record who applied each adjustment and authorize
  users to read or change the relevant payroll lines before enabling multi-user access.

- **Employees and payroll periods:** link each payroll line to an employee and period,
  keeping separate streams so an override in one period does not affect another.

- **Paginated audit history:** return events in pages for long histories, reading the
  current amount and frozen baseline from current state.

- **Currency-specific precision:** define fractional digits per currency and use them
  in parsing and formatting before enabling currencies with zero or three fractional digits.
