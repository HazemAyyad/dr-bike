# Validation Quickstart: Online Store Management

This guide is for implementation validation after tasks are completed. It does not authorize running
migrations or tests against the local production-like database.

## Prerequisites

1. PHP/composer dependencies installed for the existing Laravel project.
2. A disposable MySQL/MariaDB test schema with credentials supplied through a dedicated testing env.
3. Queue/notification fakes for automated tests; separate sandbox credentials for optional Shiply/FCM
   smoke tests.
4. Sanitized fixtures representing Product with/without variants, customer, supplier, Store user,
   stock, boxes/daily session, delivery address/company, and ledger history.
5. Do not import or alter `DB/dr_bike.sql` in place. If used to validate schema compatibility, restore
   it into a disposable database and keep the dump read-only/ignored.

## Static and isolated checks

```powershell
php -l app/Services/OnlineStore/OnlineStoreCheckoutService.php
php vendor/bin/phpunit tests/Unit/OnlineStore
php artisan test tests/Feature/OnlineStore --env=testing
```

The exact class/test paths are finalized in `tasks.md`. Before running database-backed tests, verify
the resolved database name is the disposable test schema. `phpunit.xml` alone does not force SQLite.

## End-to-end validation scenarios

### 1. Schema and compatibility

- Apply migrations twice in the disposable environment; the second deployment path is safe.
- Verify every FK/index/unique constraint in `data-model.md`.
- Seed pre-migration SalesOrders and verify every row becomes `origin=admin`; verify new Admin and Store
  writes become `admin` and `store` without serial inference, and Admin can omit `client_request_id`.
- Run frozen `routes/api_store.php` contract tests before and after adapter enablement.
- Expected: no core Product/category/location/order/ledger row is copied or destructively changed.

### 2. Listing lifecycle and media

- Create one listing for an existing Product, attempt a second, complete it, publish, hide, and reopen.
- Verify first creation initializes View Images by ID, then Normal Images by ID, then 3D Images by ID,
  and chooses the first valid row as Store main. Reorder/hide/select another main afterward.
- Create a Product with no valid media and attempt publication; add valid Product media without a
  Store-specific upload and re-run readiness.
- Expected: one listing row throughout; duplicate rejected; incomplete publication blocked; source
  media unchanged; deterministic Store presentation retained; readiness explains missing main media.

### 3. Pricing, stock, promotions, and coupons

- Submit falsified client prices/totals and confirm the server uses base price plus allowed discounts.
- Deplete stock between preview and submit.
- Race two requests for the final coupon use.
- Activate a global promotion with no targets, then attempt a targeted promotion with zero/invalid
  targets.
- Expected: no base-price mutation; zero-stock listing remains visible but cannot be ordered; at most
  one final coupon redemption succeeds; targeted activation fails until a valid target exists.

### 4. Home-section composition

- Create manual Categories and product sections, an automatic best-sellers section, a maintenance
  section, and a hero section backed by scheduled banners. Try arbitrary/incompatible target types.
- Expected: categories reference online categories, product sections reference listings, hero uses
  banners, automatic/maintenance sections use validated config, invalid targets fail, and visible
  results use deterministic `sort_order,id`/selector tie-breaks.

### 5. Identity, IDOR, and order history

- Link one User to a customer and supplier; create Admin- and Store-origin orders.
- Attempt reads/writes with another Store token and with arbitrary IDs.
- Expected: the linked user sees authorized orders across origins; cross-account access reveals no
  protected data; account roles remain explicit.

### 6. Order idempotency and stock

- Submit the same `client_request_id` concurrently and retry after the first response is lost; submit
  that same ID from a second authenticated account.
- Expected: one SalesOrder, one item set/reservation, one coupon redemption, and no duplicate status,
  stock, payment, debt, journal, or notification effects for the first actor; the second actor neither
  receives nor conflicts with the first actor's order.
- Replay an unchanged legacy request concurrently/within two minutes, then intentionally place the same
  order after the window. Expected: the near retry returns the accepted order and the later purchase is
  new. Record explicitly that unchanged legacy clients do not have strict late-retry idempotency.

### 7. Customer and supplier credit

- For each eligible role, place a 2,000 order with 500 paid; repeat with ineligible and over-limit roles.
- Expected: eligible flow records exactly 500 paid and 1,500 ledger debt with matching accounting;
  ineligible/over-limit attempts have no partial effects; available credit is ledger-derived.

### 8. Fulfillment and reversals

- Confirm, prepare, hand over via internal delivery and Shiply sandbox, deliver, settle, cancel/return.
- Expected: existing status logs, stock movement, delivery/Shiply records, settlements, and accounting
  reversals remain consistent with non-Store SalesOrders.

### 9. Password-reset security and rollout gate

- Against the updated Store client, request reset for existing/non-existing email, verify wrong/expired/
  reused/cross-account OTPs and proofs, then complete a valid reset.
- Expected: generic forgot responses, no OTP or reset proof in logs/responses beyond the opaque proof
  issued after verification, server-bound single-use proof, rate limits, and no userId-only reset.
- Confirm rollout does not enable this secure replacement until the required client version is adopted;
  never keep the insecure response OTP as a fallback.

### 10. Permissions and audit

- Exercise each Admin endpoint with no permission, its exact permission, and admin role.
- Change publication, promotion, coupon, settings, link, and review state.
- Expected: backend denial is independent of UI; audit has actor/action/entity/time/relevant before and
  after values; no token/password/OTP is logged.

### 11. Dashboard/report reconciliation

- Load a known dataset containing owner-confirmed historical Admin plus new Admin/Store origins,
  retail/wholesale orders, zero stock,
  coupons, reviews, and credit activity.
- Expected: every aggregate matches direct authoritative records and all rows classify admin/store; 95%
  of accepted catalog/dashboard/report actions complete within 2 seconds at 10,000 listings.

## Exit criteria

- Constitution check remains PASS.
- All feature, integration, compatibility, IDOR, concurrency, finance, and reconciliation tests pass.
- Migration/backfill report proves all historical rows are Admin-origin and has no unexplained identity
  assignment.
- Static checks are reported separately from live Shiply/FCM/device proof.
- Rollback switch and recovery procedure are exercised without deleting new financial/audit history.
