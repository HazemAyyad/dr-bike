# Implementation Plan: Online Store Management

**Branch**: `feat/online-store-management` | **Date**: 2026-10-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-online-store-management/spec.md`

## Summary

Add an authenticated Admin management surface and a compatibility-safe Store read/order path on top
of Doctor Bike's existing Product, variant stock, SalesOrder, party, debt, accounting, delivery,
Shiply, permission, media, and notification domains. New `online_store_*` persistence is limited to
merchandising, content, eligibility, redemption, settings, and audit facts that do not exist in the
current schema. Store orders remain `sales_orders`; Store checkout resolves authoritative prices,
discounts, stock, identity, and credit policy server-side before calling the existing order lifecycle.

The rollout is additive. Existing `routes/api_store.php` response shapes remain available while their
internals are progressively routed through shared application services. `store_sections` remains a
physical-location concept and is never used as an online category.

## Technical Context

**Language/Version**: PHP 8.2.1 CLI; project constraint PHP `^8.1`

**Primary Dependencies**: Laravel 10, Laravel Sanctum 3, Eloquent, Firebase/FCM through
`kreait/laravel-firebase`, Guzzle, existing Shiply integration

**Storage**: MySQL/MariaDB production schema; `DB/dr_bike.sql` (2026-10-01 snapshot) is the planning
schema authority. Money remains decimal in database persistence; no float-valued new money columns.

**Testing**: PHPUnit 10 with Laravel Unit and Feature suites; database-backed feature/integration
tests require an isolated test schema because `phpunit.xml` does not force SQLite

**Target Platform**: Existing Laravel HTTP API and queue/scheduler runtime used by Doctor Bike Admin
and customer Store applications

**Project Type**: Existing monolithic Laravel web service with mobile API consumers

**Performance Goals**: For an acceptance catalog of 10,000 listings, 95% of catalog searches,
filters, dashboard loads, and report openings return usable results within 2 seconds; coupon and order
writes remain transactionally safe under concurrent requests

**Constraints**: No `online_store_orders`; no Store base-price override; one listing per Product;
zero-stock listings stay visible/non-purchasable; no reuse of `store_sections`; legacy Store contracts
remain compatible; all authorization, pricing, stock, credit, and totals are server-authoritative

**Scale/Scope**: 10 ordered implementation phases, 7 Admin permission capabilities, 54 approved
functional requirements, current production data in existing core tables, and genuinely new
merchandising/configuration persistence described in `data-model.md`

## Constitution Check

*GATE: Passed before research and re-checked after design.*

| Gate | Plan evidence | Result |
|------|---------------|--------|
| Single source of truth | Listings reference `products`; orders remain `sales_orders`; balances remain ledger-derived | PASS |
| Explicit domain boundaries | New merchandising tables use `online_store_*`; `store_sections` is excluded | PASS |
| Server authority and financial integrity | Checkout pricing, stock, coupon use, payment, and credit are calculated in one server transaction | PASS |
| Backward compatibility | `routes/api_store.php` stays mounted; safe shapes remain compatible, while insecure password reset has an explicit client migration gate | PASS |
| Unified identity and ownership | `online_store_account_links` references existing `users`, `customers`, and `sellers` | PASS |
| Security | Sanctum identity replaces trusted request IDs on protected operations; policy/middleware checks cover every resource | PASS |
| Permissions and auditability | Existing permission tables receive seven seeded permissions; Store-specific sensitive changes use one audit table | PASS |
| Testing and regression safety | Every phase has feature/integration coverage, including finance, stock, IDOR, concurrency, and compatibility | PASS |
| Migration and data safety | Additive nullable/default-safe columns, owner-confirmed Admin-origin backfill, FK/index checks, and no destructive legacy changes | PASS |
| Simplicity and reuse | Existing services are extended; new services own only Store orchestration and merchandising rules | PASS |

No constitutional exception or complexity waiver is required.

### Post-design re-check

The data model introduces no duplicate Product, variant, order, party, debt balance, payment, stock,
delivery, or notification source. It adds no Store base-price override, leaves `store_sections` as
physical inventory/location data, and keeps server ownership/permissions authoritative. Contracts
require existing service orchestration, actor-scoped idempotency before transaction side effects, and
legacy response preservation where security permits. All persistence is additive and compatibility-
safe. The gate remains **PASS** after the corrected Phase 1 design.

Explicit re-check: Product authority remains `products`; order authority remains `sales_orders` (no
`online_store_orders`); stock stays with existing inventory/order services; debt/current credit stays
ledger-derived; Store has no base-price override; `store_sections` remains physical inventory/location
only; legacy contracts are preserved except where the insecure reset requires a documented client
migration; server-side ownership and permissions remain authoritative; financial/idempotent writes are
transaction-safe; and every new table/column is additive with staged compatibility-safe migration.

## Project Structure

### Documentation (this feature)

```text
specs/001-online-store-management/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── admin-api.md
│   ├── checkout-integration.md
│   └── legacy-compatibility.md
└── tasks.md                     # created later by $speckit-tasks, not by this plan
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/API/
│   │   ├── Store/               # legacy compatibility controllers retained
│   │   └── OnlineStore/         # new authenticated Admin management endpoints
│   ├── Middleware/CheckPermission.php
│   └── Requests/OnlineStore/    # management and checkout validation
├── Models/
│   ├── Product.php, SalesOrder.php, Customer.php, Seller.php, User.php
│   └── OnlineStore/             # models only for new online_store_* records
├── Policies/OnlineStore/        # ownership and resource-level authorization where needed
├── Services/
│   ├── SalesOrderService.php, SalesOrderStockService.php
│   ├── SalesOrderFulfillmentService.php, DebtLedgerService.php
│   ├── AccountingProjectionService.php, AdminNotificationService.php
│   └── OnlineStore/             # merchandising, pricing orchestration, identity, audit, reports
└── Observers/                   # existing accounting observers retained

database/
├── migrations/                  # additive online_store_* tables and sales_orders columns
└── seeders/                     # idempotent Store permission seeding

routes/
├── api.php                      # new authenticated Admin Online Store routes
└── api_store.php                # unchanged public route names and response contracts

tests/
├── Feature/OnlineStore/         # API, compatibility, IDOR, concurrency, reporting tests
└── Unit/OnlineStore/            # rule/state/value-object tests
```

**Structure Decision**: Keep the existing Laravel monolith. Add a bounded `OnlineStore` namespace
inside current application layers; do not create a package, second service, or second order domain.

## Existing Domains and Required Reuse

| Concern | Existing source to reuse or extend | Planned use |
|---------|------------------------------------|-------------|
| Product identity/base price | `Product`, `Size`, `SizeColor`, `WholesaleProduct` and current pricing fields | Listing references Product; Store pricing resolver reads retail/wholesale/variant sources and never writes them |
| Stock | `SalesOrderStockService`, `ProductStockService`, `ProductStockMovement` | Read availability; reserve/dispatch/release only through existing order services |
| Media | `NormalImageProduct`, `Image3dProduct`, `ViewImageProduct`, `MediaUploadService`, `ProductImageResolver` | Initialize valid media by view/normal/3D priority and source ID; Store main/order/hiding never mutates source media |
| Orders | `SalesOrderService`, `SalesOrderStockService`, `SalesOrderFulfillmentService`, `SalesOrderStatusLog` | Store adapter creates/advances existing SalesOrder records and preserves lifecycle logs |
| Parties | `User`, `Customer`, `Seller`, `PartnerAddress` | Explicit account link resolves a Store user to customer and/or seller identity |
| Debt | `DebtLedgerService`, `DebtTransaction`, `DebtLedgerActivityLogger` | Extend sales-order ledger sync to resolve customer or seller from order partner identity |
| Accounting | `AccountingProjectionService`, `AccountingService`, accounting observers | Extend sales-order and settlement projections for seller-linked orders; retain source-key idempotency |
| Payments | `SalesOrderSettlement`, `SalesOrdersDailyBoxService`, `SalesDailySessionService` | Reuse idempotent initial/delivery payment flow; do not add Store payment tables |
| Delivery/Shiply | `SalesOrderFulfillmentService`, `ShiplyService`, `SalesOrderShiplyTrackingService`, delivery/event tables | Existing handover, delivery, tracking, fee, and reversal paths continue unchanged |
| Permissions | `permissions`, `employee_permissions`, `CheckPermission`, `User::hasEmployeePermission()` | Seed and enforce the seven approved Online Store permissions |
| Notifications | `AdminNotificationService`, `SalesOrderNotificationService`, notification templates/control tables, FCM devices | Add Store templates/types and deep-link metadata without a parallel notification system |
| Audit | domain-specific activity/audit tables exist, but no generic Store audit | Add `online_store_audit_events` only for Store management state; existing domain audits retain ownership |

## Verified Schema Changes

The dump contains no `online_store_*` table and no order-origin column. Every addition below is
described field-by-field in `data-model.md`.

### New tables

1. `online_store_listings`
2. `online_store_categories`
3. `online_store_category_listing`
4. `online_store_media_presentations`
5. `online_store_promotions`
6. `online_store_promotion_targets`
7. `online_store_coupons`
8. `online_store_coupon_targets`
9. `online_store_coupon_redemptions`
10. `online_store_home_sections`
11. `online_store_home_section_items`
12. `online_store_banners`
13. `online_store_account_links`
14. `online_store_credit_policies`
15. `online_store_reviews`
16. `online_store_settings`
17. `online_store_audit_events`
18. `online_store_legacy_checkout_attempts` (expiring actor-scoped compatibility dedupe only; not an
    order table)

### Existing-table additions

- `sales_orders.origin`: add nullable, backfill every existing production row to owner-confirmed
  `admin`, then make non-null/default `admin`; new Admin writes use `admin`, Store writes use `store`.
  Keep a bounded extensible value rule for future origins without an unknown V1 bucket.
- `sales_orders.origin_user_id`: nullable FK to `users`, preserving the Store authentication actor
  independently from staff `created_by`.
- `sales_orders.client_request_id`: nullable request id unique with `(origin, origin_user_id,
  client_request_id)`, used only with authenticated actor scope to return the same accepted order.

No price, stock, debt-balance, or product-category column is added for Store ownership.

### Migration safety

- Create new tables before exposing routes; all new FKs target existing authoritative tables and use
  `restrictOnDelete` or `nullOnDelete` according to whether history must remain.
- Add sales-order columns nullable/default-safe first. Backfill all pre-migration production
  `sales_orders` to `origin='admin'` per the confirmed business rule, record count/checksum evidence,
  then enforce non-null/default `admin`. Do not inspect or infer from serials, notes, or UI metadata.
- Add unique `(origin,origin_user_id,client_request_id)` only after duplicate/null analysis. Nullable
  request IDs preserve historical and Admin workflows; Admin is not forced to provide one.
- Seed permissions with `updateOrInsert`/equivalent idempotent behavior and do not replace employee
  grants.
- Do not migrate legacy inventory categories into online categories automatically. Merchandising is
  curated explicitly.
- Do not modify `DB/dr_bike.sql`; validate migrations against an isolated copy/schema.

## Integration Maps

### Store order creation to SalesOrder

```text
authenticated Store user + client_request_id (native checkout)
  -> online_store_account_links ownership check
  -> listing/variant publication and stock-read check
  -> authoritative base-price resolver
  -> promotion and coupon evaluator (locked redemption scope)
  -> credit policy + ledger-derived available-credit check when applicable
  -> Store checkout adapter builds a server-owned SalesOrder command
  -> SalesOrderService::store (origin metadata included)
  -> existing reservation/status/notification behavior
  -> existing confirm/fulfillment path at the approved lifecycle point
```

The Store adapter MUST NOT pass client prices or an arbitrary discount into the core order service.
The shared service requires a trusted, server-generated price snapshot for Store origin, while Admin
order behavior remains backward compatible.

### Credit and partial payment to debt/accounting

```text
explicit eligible account link + optional credit limit
  -> DebtLedgerService current balance by customer/seller and currency
  -> available credit = configured limit - authoritative outstanding exposure
  -> existing SalesOrder payment/settlement path posts paid portion idempotently
  -> SalesOrderFulfillmentService posts recognized total
  -> extended DebtLedgerService::syncSalesOrderToLedger posts only unpaid remainder
  -> AccountingProjectionService posts matching party receivable/revenue/inventory entries
```

Seller-linked orders require extending current customer-only branches in both ledger and accounting
projection. No Store balance is stored; policy rows hold eligibility/limit only.

### Identity, listing, and media mappings

- `users.id` -> `online_store_account_links.user_id` -> exactly one `customer_id`, `seller_id`, or one
  of each across role rows; ownership queries resolve through this link, never request-supplied IDs.
- `products.id` -> unique `online_store_listings.product_id`; lifecycle changes update the same row.
- Source media (`view_image_products`, `normal_image_products`, `image3d_products`) -> initial
  `online_store_media_presentations` in that priority, with source ID as deterministic within-type
  order and the first valid row as main. Inventory media has no persisted sort/main field. Later Store
  main/order/hide choices mutate presentation rows only; no media means draft is allowed but
  publication is blocked. Store-specific media is exceptional, not mandatory.

## Implementation Phases

### Phase 1 — Schema and foundation

- Add enums/value rules for listing state, target types, promotion/coupon types, banner actions, and
  audit actions without database-native enums that make rollout brittle.
- Add new merchandising/configuration tables and compatibility-safe sales-order origin/idempotency
  columns in dependency order.
- Add models/relations and database constraints; keep all feature routes disabled until foundation
  migrations and read services are verified.
- Tests: migration up/down on isolated schema, FK/unique/index assertions, one-listing-per-product,
  all-existing-orders-to-admin backfill counts, actor-scoped request uniqueness, nullable Admin/order
  compatibility, and no mutation of core product/category/location data.

### Phase 2 — Identity and order-source foundation

- Implement account-link management and ownership resolver for customer, supplier, or both.
- Backfill every historical production order to `admin` from the confirmed business rule; new writes
  explicitly set `admin` or `store`, without serial inference or an unknown V1 classification.
- Add Store request idempotency lookup/locking scoped by authenticated actor and origin before any
  stock/coupon/payment/debt/accounting/notification/status side effect; add origin report filters.
- Tests: duplicate/conflicting links, soft-deleted/blocked users, IDOR, cross-account order history,
  full Admin backfill fixtures, two Users sharing a request ID without collision/disclosure, same-User
  concurrent duplicate `client_request_id`, and Admin calls without a request ID.

### Phase 3 — Catalog, listings, categories, and media

- Implement listing completeness/readiness service and lifecycle guard.
- Implement independent hierarchical categories and many-to-many membership.
- Initialize media presentation from valid Product media using view -> normal -> 3D and source-ID
  order, then allow Store-only main/order/visibility changes without source mutation.
- Read stock through `SalesOrderStockService`; zero-stock listings stay visible and non-purchasable.
- Tests: publication blockers including no usable main image, initialization order/default main,
  independent reorder/hide/main selection, no source-media mutation, source deletion, category cycles,
  multilingual fallback, mixed variant availability, no stock edit endpoint, no second hidden listing.

### Phase 4 — Storefront content

- Implement typed manual home items (`listing|category`), validated automatic `selection_config`, and
  dedicated banner composition for hero. Enforce the per-section compatibility matrix and
  deterministic `sort_order,id` ordering.
- Provide Store-facing composition reads behind compatibility adapters where legacy shapes need them.
- Tests: listing/category manual ordering, hero banners, maintenance config, visibility/time boundaries,
  invalid/arbitrary or incompatible targets, unpublished/inactive omission, stable automatic tie-breaks.

### Phase 5 — Promotions and coupons

- Implement authoritative promotion/coupon eligibility and deterministic non-stacking precedence;
  promotions use explicit `scope=global|targeted`, and targeted activation requires valid targets.
- Persist coupon redemptions only for accepted orders; use transaction locks and unique keys to
  enforce global/per-user limits under concurrency.
- Do not expose base-price writes in Store management.
- Tests: retail/wholesale targeting, dates/minimums/limits, product/category targets, negative-price
  guard, global promotion without targets, targeted zero-target rejection, invalid target types,
  concurrent last-use redemption, cancellation/reversal policy, server rejection of client totals.

### Phase 6 — Credit and order integration

- Introduce a Store checkout orchestrator that resolves identity, price, discount, stock, delivery,
  credit, and idempotency before invoking `SalesOrderService`.
- Native checkout requires actor-scoped request IDs. Unchanged legacy checkout gets only a two-minute
  authenticated-actor/canonical-payload lock and match (items/variants/quantities, coupon, delivery,
  payment intent; excluding client totals, timestamps, and request user IDs). It returns an accepted
  match through an expiring compatibility-attempt row within that window; every accepted order stores
  a separate server UUID, so an expired identical request can create a new order. This does not claim
  strict retry idempotency afterward; migrate the Store client to generate one UUID per checkout
  attempt for full FR-031 behavior.
- Extend SalesOrder validation/service entry points to accept trusted origin metadata without changing
  existing Admin contracts.
- Extend `DebtLedgerService::syncSalesOrderToLedger` and `AccountingProjectionService` to resolve
  customer or seller party identity consistently.
- Tests: native same-actor retry idempotency, cross-actor request-ID isolation, lookup-before-effects,
  legacy concurrent/near-retry mitigation and post-window intentional repeat, stock race/rollback,
  cash/credit/mixed payments, 2,000/500/1,500
  reconciliation, customer and seller credit, credit-limit concurrency, ledger/accounting equality,
  cancellation/reversal, delivery and Shiply regression.

### Phase 7 — Reviews and notifications

- Implement review submission/moderation and server-derived verified-purchase flag.
- Add Store notification types/templates and deep-link metadata through existing notification services.
- Tests: review ownership/IDOR, moderation permissions, verified-purchase derivation, blocked users,
  template locale fallback, FCM payload shape without exposing sensitive data.

### Phase 8 — Settings, permissions, and audit

- Seed and enforce seven granular permissions on every Admin endpoint.
- Implement typed Store settings and effective operating-state precedence.
- Log sensitive Store actions in `online_store_audit_events`; base product price changes remain in the
  existing pricing/inventory domain and are not duplicated.
- Tests: every permission independently, direct-request denial, before/after audit snapshots, actor
  attribution, maintenance/store/checkout/COD/guest combinations, secret/sensitive-field exclusion.

### Phase 9 — Dashboard and reports

- Build indexed aggregates from listings, authoritative sales orders by `origin`, promotions,
  redemptions, reviews, stock reads, and ledger transactions.
- Reports state filters/timezone and reconcile explicit `admin`/`store` origins; V1 has no historical
  unknown bucket because all pre-migration production rows are confirmed Admin-origin.
- Tests: seeded reconciliation dataset, retail/wholesale split, average-order value, Store debt activity,
  out-of-stock counts, complete origin counts, 10,000-listing query-budget/performance acceptance.

### Phase 10 — Compatibility and regression verification

- Freeze legacy `routes/api_store.php` request/response fixtures before replacing internals.
- Route legacy item/order/settings/comment operations through new read/orchestration services only
  where responses remain byte/shape compatible; retain an explicit rollback switch during rollout.
- Treat password reset as a security-gated client migration: current Flutter requires response OTP and
  reset-by-userId, so ship the minimum verify-OTP/reset-proof client change before enabling the secure
  server flow. Never preserve OTP leakage or userId-only reset for compatibility.
- Verify Admin and Store critical journeys, migration/backfill reports, finance reconciliation, queues,
  notifications, delivery, Shiply, and status logs before enabling management capabilities.
- Do not introduce `/api/store/v2` or remove legacy routes in this feature.

## Legacy Store Risks in Scope

1. Legacy Store routes are not grouped under `auth:sanctum`; several operations accept user/order IDs
   from input. Protected writes must resolve the bearer token and enforce ownership without changing
   public catalog compatibility.
2. `StoreOrdersController::manageOrder()` directly creates `sales_orders`, `sales_order_items`, and a
   status log instead of using `SalesOrderService`; it bypasses reservation, server pricing,
   idempotency, financial lifecycle, and normal notifications.
3. The controller derives `unit_price` from request item values and calculates totals locally. The new
   adapter must ignore those values for authority while returning the legacy response shape.
4. `StoreItemsController` exposes all `products.isShow` rows and interprets `store_section_id` as a
   main category. Preserve this behavior until compatibility tests support a deliberate adapter;
   never treat `store_sections` as the new merchandising taxonomy.
5. Store registration writes directly to shared `users` with type `User` but creates no customer or
   seller link. Existing accounts require an explicit linking workflow and duplicate detection.
6. The forgot-password response includes OTP and Flutter parses/compares it locally; reset then submits
   only user ID/new password. A secure server-only correction is impossible without a client update.
   The rollout dependency is a minimum client change to server OTP verification and a short-lived,
   single-use, account-bound reset proof. Use generic responses and rate/attempt/expiry controls;
   never log or return secrets, and disable the insecure reset when the compatible client ships.
7. Legacy checkout has no stable request-attempt ID. The bounded two-minute actor/payload fingerprint
   mitigates concurrent/near retries but cannot distinguish a late retry from an intentional identical
   purchase. Full idempotency requires the client UUID migration; do not claim otherwise.
8. Legacy comments are stubs and settings read optional keys from `app_settings`; the dump contains no
   persisted Store setting rows. New reviews/settings require explicit persistence plus response
   adapters rather than assumptions about existing data.

## Test Strategy Matrix

| Phase | Unit coverage | Feature/integration coverage | Critical proof |
|-------|---------------|------------------------------|----------------|
| Foundation | state/target validation | migrations and constraints | additive, retry-safe schema |
| Identity/origin | link resolver | auth, IDOR, all-admin historical backfill, admin/store history | one logical identity; reliable explicit origin |
| Catalog/media | completeness/media initialization | lifecycle, media/category/stock reads | deterministic priority/main; no Product/media/stock mutation |
| Content | scheduling/action rules | home/banner reads | deterministic visibility |
| Discounts | eligibility/precedence | transactional redemption | limits never exceeded |
| Orders/credit | price, credit, party resolution | full lifecycle with boxes/ledger/accounting | actor-scoped native idempotency, bounded legacy mitigation, 2,000-500=1,500 reconciliation |
| Reviews/notifications | verified-purchase/template rules | ownership, moderation, FCM payload | no client-asserted verification |
| Settings/permissions/audit | precedence/audit redaction | each permission and direct URL | backend authority |
| Dashboard/reports | aggregate calculators | reconciliation dataset/performance | totals match authoritative sources |
| Compatibility | serializers/adapters | frozen `api_store` suite plus reset-client migration tests | safe clients remain operational; insecure reset is gated |

Database-backed tests MUST run against an isolated disposable schema loaded from migrations or a
sanitized schema fixture, never the developer's local/production-like `dr_bike` database. Static/unit
checks do not count as end-to-end financial or compatibility proof.

## Release and Rollback

1. Deploy additive schema and permission seed with feature exposure disabled.
2. Run schema/backfill validation and legacy contract suite.
3. Enable read-only Admin catalog management for authorized users.
4. Enable Store presentation reads, then promotions/coupons, then checkout/credit separately.
5. Monitor duplicate-request conflicts, stock shortages, ledger/accounting projection failures,
   notification failures, and legacy error rates.
6. Roll back by disabling feature exposure and restoring legacy adapters; retain new tables/columns
   and historical audit/redemption/origin data. Never drop financial or audit history during rollback.

## Complexity Tracking

No constitution violations require justification. The new tables are bounded merchandising or policy
records absent from the verified schema; they do not replace existing authoritative domains.
