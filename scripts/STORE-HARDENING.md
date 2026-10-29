# Store verification and rollout

PHP 8.3 and MySQL 8.4 are used for current verification. This branch contains a separate Laravel 13 candidate; see [its acceptance record](LARAVEL13-UPGRADE.md). The store hardening release remains independently available at `51521bc`.

## Disposable database

Create an empty local `doctor_bike_laravel13_test` database for this upgrade branch. Never point tests at development or production. `scripts/test-store.ps1` sets a fixed local test connection, explicit disposable opt-in, array mail/cache/session and synchronous queue. The base guard checks the resolved database before Laravel's database setup traits run. Laravel HTTP calls are denied unless explicitly faked.

```powershell
./scripts/test-store.ps1 -ExcludeGroup 'performance,schema'
./scripts/test-store.ps1 -Filter OnlineStoreSchemaMigrationTest
./scripts/test-store.ps1 -ReuseSchema -TestPaths tests/Feature/OnlineStore/StoreCatalogPaginationTest.php
```

Functional fixtures use one schema per process and a transaction per test. Schema-changing tests keep real DDL and group `schema`; benchmarks use group `performance` so they run separately from compilation. `-ReuseSchema` requires a complete, already migrated disposable schema. Never reuse after an interrupted migration or a DDL test removed a table. CI runs functional, schema and performance checks separately.

Checkout tests verify coupon/stock effects precede notification and transaction effects roll back on failure. Bulk availability is compared with the individual stock authority, including hidden variants, dispatches and excluded orders. Catalog checks cover stable pages, live prices/promotions, readiness and a 10,000-product p95 below two seconds with bounded queries. Synthetic evidence does not establish production latency.

## Compatible API additions

* `POST /Auth/CheckUser` uses the authenticated store actor and rejects a supplied foreign `UserId`.
* `POST /Auth/Logout` revokes the presented token; optional `fcm_token` clears only a matching device token. Success returns 204.
* `GET /OnlineStore/Catalog` supports `page`, `per_page` (maximum 48), `q`, `category_id`, `minPrice`, `maxPrice`, `availableOnly`, `onSale`, `sort`. `rows` retain the legacy product shape; `paginationInfo` adds page/count/hasMore. Prices, reservations and stock use existing authorities.
* Shipping accepts a configured finite nonnegative fee, including explicit zero, or a valid Shiply quote. Unavailable quotes return 503 and `delivery_quote_unavailable`; they never silently become free shipping. Checkout recalculates on the server.
* Store responses carry `X-Request-ID`; logs contain route template, status, duration and request ID.

## Deployment acceptance

Prepare review, compatible APK/server revisions, tested backups and rollback instructions before requesting deployment. Production migrations are not delegated by this work. Verify the administration app too: pricing, stock, orders and finance are shared. Keep the separate Laravel upgrade out of the urgent rollout.

After separately authorized deployment, verify health/authentication, existing clients, shipping failures, checkout idempotency and financial reconciliation. Roll back compatible code without dropping or rewriting operational data. Completion requires real APK upgrade and 48-hour observation evidence.
