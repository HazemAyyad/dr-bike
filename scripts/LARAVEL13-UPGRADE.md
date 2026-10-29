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
  `doctor_bike_laravel13_schema_test`; schema rollback and performance checks
  are recorded separately. Composer audit reported no known advisories.
- Administration baseline on Laravel 10 had 10 errors and 7 failures across 675
  cases. Test fixtures were corrected for missing audit tables, receipt identity,
  timestamps, future-task dates, Windows fake-upload handles, inventory route
  permissions and valid opening-stock cost coverage. Assertions were retained.
- The latest focused inventory/invoice regression run passed 28 tests / 198
  assertions. Legacy schema preservation/idempotency passed 2 tests / 8 assertions.

There is no production, phone, APK, or remote CI acceptance in this branch yet.
