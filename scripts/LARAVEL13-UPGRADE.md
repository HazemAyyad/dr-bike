# Laravel 13 upgrade candidate

This branch is separate from the store hardening release (`51521bc`). It must not
be deployed until store and administration regressions, hosting compatibility,
and rollback acceptance are complete. No production migration is authorized.

## Compatibility changes

- PHP 8.3; Laravel 13; Sanctum 4; Reverb 1; Firebase Laravel bridge 7;
  Tinker 3; PHPUnit 12. Exact dependency versions are in `composer.lock`.
- Keep the existing HTTP/console kernels, providers, routes and permission model.
- Sanctum uses the current session/CSRF middleware configuration. The existing
  application CSRF wrapper now extends `PreventRequestForgery`; fingerprint
  device exclusions remain explicit.
- Retain the session cookie configuration and PHP serialization used by existing
  administration sessions. Do not change `APP_KEY` during the upgrade.
- Cache payloads are scalars/arrays; object unserialization is disabled explicitly.
- Carbon interval callers retain whole-number truncation and their previous
  absolute/signed behavior. This covers attendance, overtime grace, recurrence,
  debt aging, reporting windows and cron durations.
- PHPUnit providers use attributes. The store transaction manager receives its
  connection list explicitly.
- Request unqualified schema table names explicitly: Laravel's new default can
  otherwise hide optional dashboard review/debt metrics behind schema prefixes.
- PHPUnit 12 accepts repeated group options; the runner expands comma-separated
  inputs into separate options. Validate selection with `-ListTests` before a
  mixed suite. Schema and performance groups must remain separate processes.

## Local verification

`scripts/test-store.ps1` in this branch uses only
`doctor_bike_laravel13_test`, with a disposable-database guard and a fake app key.
It must never be pointed at a development or production database.

```powershell
./scripts/test-store.ps1 -TestPaths tests/Unit/FrameworkIntervalCompatibilityTest.php
./scripts/test-store.ps1 -TestPaths tests/Unit,tests/Feature -ExcludeGroup 'performance,schema'
./scripts/test-store.ps1 -Filter OnlineStoreSchemaMigrationTest
./scripts/test-store.ps1 -TestPaths tests/Feature/OnlineStore -Group performance
```

Run performance cases alone. Never use `-ReuseSchema` after schema/rollback tests.
The quality workflow includes store, administration/accounting, schema and
performance checks; local success does not establish a remote CI result.

Local intermediate Laravel 11 and 12 dependency/runtime checks must also be
recorded before accepting the final transition. Intermediate versions are never
deployment targets.

## Release and rollback acceptance

Verify PHP 8.3 and the extensions required by `composer check-platform-reqs` on
the hosting runtime, including both web PHP and scheduler/queue PHP. Verify Arabic
PDF, Excel, uploads/storage access, Firebase message construction, Reverb
authorization and scheduled commands. External delivery still needs a controlled
environment; mock tests alone do not prove device delivery.

Before a separately authorized deployment, capture the exact code revision,
dependency lock, environment/storage backup and database backup, and verify a
restore in an isolated environment. Preserve `.env`, `APP_KEY`, uploaded files and
operational data. A separate, idempotent fresh-install compatibility migration
renames snake_case category columns only when their legacy model names are
missing, and adds five missing nullable/defaulted product fields. It preserves
existing legacy columns and values, and does not remove data on rollback. It has
only been applied to a guarded disposable local database. Production execution
remains the user's responsibility unless separately delegated.

Rollback uses the previous code and its own `composer.lock`/vendor artifact,
followed by rebuilding application caches and restarting workers under the old
runtime. Do not reverse unrelated business migrations or delete live data.

Official transition references: [Laravel 11](https://laravel.com/docs/11.x/upgrade),
[Laravel 12](https://laravel.com/docs/12.x/upgrade), and
[Laravel 13](https://laravel.com/docs/13.x/upgrade).

## Evidence

- Laravel 13.39.0 installation, package discovery and local platform requirements
  passed on PHP 8.3.33 with zip enabled per process. The Composer Windows curl
  download path failed on large archives; the verified installation used PHP
  streams with TLS verification intact. No global PHP configuration was changed.
- Laravel 12.70.2 intermediate unit suite: 418 tests, 2157 assertions passed.
  Its focused contracts passed 27 tests / 89 assertions, including Arabic PDF
  rendering and Firebase payload construction with a mocked transport.
  Intermediate store functional suite: 186 tests / 1779 assertions, no failures;
  one dump-dependent case skipped.
- Laravel 11 dependency resolution was rejected by Composer security advisories;
  its runtime was not installed and no advisory override was added. This is a
  documented deviation from the planned intermediate-runtime sequence.
- The initial broad run failed after schema/performance groups were mistakenly
  selected together, and is not acceptance evidence. The corrected full
  functional suite passed 683 tests / 4514 assertions without failures, errors
  or PHPUnit notices; one dump-dependent case skipped. Log:
  `storage/logs/framework-functional-verified.log`.
- Independent fresh schema: 1 test / 81 assertions passed on
  `doctor_bike_laravel13_schema_test`; full schema/rollback group passed
  5 tests / 90 assertions. Composer audit reported no known advisories.
- Administration baseline on Laravel 10 had 10 errors and 7 failures across 675
  cases. Test fixtures were corrected for missing audit tables, receipt identity,
  timestamps, future-task dates, Windows fake-upload handles, inventory route
  permissions and valid opening-stock cost coverage. Assertions were retained.
- The latest focused inventory/invoice regression run passed 28 tests / 198
  assertions. Legacy schema preservation/idempotency passed 2 tests / 8 assertions.
- The user supplied the 28 October database export. Its original SHA-256 is
  `0e6d8563055c3ba8bcb20c2d2aca7076996493546328f113f9de1fe38cb68050`.
  A separate disposable local restore contains 289 tables. Three MariaDB text
  default literals required expression parentheses for MySQL 8; the original
  export and all row values were left unchanged. A temporary import account had
  privileges only on that disposable database and was removed after import.
- The restored export already recorded all 432 prior migrations. The only pending
  migration was the separately committed fresh-catalog compatibility repair.
  After applying it locally, and again after catalog/dashboard/report/cashbox
  reads, fingerprints of every original column in 288 business tables and
  273677 rows matched the restored baseline. The migration ledger was the only
  table excluded from row fingerprints. No customer values or credentials are
  included in this repository.
- Dump-derived migration and runtime contracts: 2 tests / 18 assertions passed.
  The test supports both a historical pre-store schema and an already upgraded
  export, respecting its recorded migrations and existing order origins.
- Two performance runs overlapped schema/import verification and exceeded the
  unchanged two-second threshold. They are failed checks, not acceptance results.
  A further unloaded run still failed the catalog gate (p95 5.199s), while the
  administration/report case passed at 0.640s with 29 queries. Query profiling
  located the catalog bottleneck in wide page selection before sorting.
- Catalog page selection now sorts/filters narrow listing identities and loads
  full translated payloads and review aggregates only for the selected page.
  Filters, readiness and live stock/price authorities remain unchanged. The
  focused pagination/authority suite passed 12 tests / 108 assertions, including
  stable order and published-only review aggregation. The optimized 10000-product
  case passed 82 assertions at p95 0.667s / 21 queries / 20 samples. The two-second
  gate was not relaxed. Log: `storage/logs/framework-catalog-optimized-performance.log`.
- Final complete functional run after catalog optimization: 685 tests / 4522
  assertions, no failures, errors or PHPUnit notices; the two dump-only cases
  were skipped in this synthetic suite and passed separately against the supplied
  export. Log: `storage/logs/framework-functional-release.log`.

Local changes are committed independently: fresh-schema repair `95e6a9f`,
framework upgrade `f0bf0ee`, catalog page optimization `b0eb051`. The primary
Laravel checkout and production have not been advanced to this upgrade branch.

Outstanding acceptance: hosting PHP/extensions, real Reverb and notification
delivery, remote CI, user-owned APK/phone checks, Apple validation, production
backup/restore and the separately authorized release observation window.

### Local Reverb and scheduler runtime check

The installed Reverb server was started on a temporary loopback-only port with
random disposable credentials and stopped after verification. A real WebSocket
client connected, obtained authorization through the application's registered
private user-channel callback, subscribed successfully, and received an event
sent through Laravel's Reverb broadcaster. Authorization for a different user's
private channel was rejected. The probe used in-memory synthetic user identities;
it sent no FCM, email, Shiply, or production WebSocket traffic.

The console kernel registered 24 schedules with valid cron expressions. Command
registration and scheduling were checked for database backup, checkout-attempt
purging, store low-stock notification, fingerprint processing and Shiply address
synchronization. No scheduled command was executed. This proves local runtime
registration, not that production cron/workers or external delivery are healthy.

The remaining Reverb gate is the hosting WebSocket/proxy/worker environment.
GitHub CLI is unavailable on this host and no remote workflow result has been
claimed. The local result is recorded in the task's `reverb-local-result.json`.

There is no production, phone, APK, or remote CI acceptance in this branch yet.
