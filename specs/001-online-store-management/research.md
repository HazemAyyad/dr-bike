# Research: Online Store Management

## Sources and method

- Read `DB/dr_bike.sql` without modification and treated its 2026-10-01 schema as authoritative.
- Compared dump definitions/indexes/FKs with migrations, Eloquent models, routes, controllers,
  services, observers, permission middleware, and existing PHPUnit coverage.
- Inspected `routes/api_store.php` and all current compatibility controllers.
- Inspected the current Flutter Store client checkout and forgot-password/reset call sites under
  `F:\flutter_projects\doctorbike_store` to verify wire-level compatibility dependencies.
- No local database writes, migrations, application changes, or live API calls were performed.

## Decision 1 — Preserve authoritative core domains

**Decision**: Store metadata references existing Products, variants, media, parties, and SalesOrders.

**Rationale**: The dump verifies mature core tables for products, sizes/colors, media, stock movements,
sales orders/items/settlements/status/delivery, customers, sellers, debt transactions, and accounting
journals. New Store persistence is absent and is appropriate only for merchandising concerns.

**Alternatives considered**: Copying products into Store tables or creating `online_store_orders` was
rejected because it creates reconciliation and compatibility failures and violates the constitution.

## Decision 2 — One listing row per Product

**Decision**: `online_store_listings.product_id` is unique; draft, ready, published, and hidden are
states of the same row.

**Rationale**: The approved spec forbids hidden/historical duplicates until a future versioning spec.
A unique FK makes the invariant concurrency-safe.

**Alternatives considered**: Partial unique indexes on active states and historical listing rows were
rejected because MySQL portability is weaker and the business rule permits no second row at all.

## Decision 3 — Separate merchandising categories

**Decision**: Create `online_store_categories` and a listing membership pivot.

**Rationale**: The dump shows inventory `categories/sub_categories` and physical `store_sections`.
Neither represents online merchandising; the legacy item controller currently misuses
`store_section_id` as `MainCategory`, which must remain only as a temporary compatibility behavior.

**Alternatives considered**: Reusing either existing taxonomy was rejected because it changes
inventory/location semantics.

## Decision 4 — Reference source media, do not copy it

**Decision**: On first listing creation, initialize Store presentation rows from every valid Product
media item in deterministic source priority `view_image`, then `normal_image`, then `image3d`, with
stable source-row ID as the tie-breaker. Select the first valid item in that sequence as the default
Store main image. Thereafter Store presentation rows order, hide, and select main independently.
Exceptional Store-only media requires an explicit source type and managed path only when Product
media cannot satisfy the presentation need.

**Rationale**: Current media exists in three product-linked tables plus variant images. The feature
needs visibility/order metadata, not another authoritative product-media library. The verified
inventory media schema has no persisted `sort_order` or `is_main`; current Doctor Bike display
priority is application-defined as view images, normal images, 3D images, then fallback where
applicable. A listing with no usable main image may remain draft but cannot be published.

**Alternatives considered**: Copying URLs into listings was rejected because source changes/deletions
would drift. Mutating source rows to hide them was rejected because inventory consumers share them.

## Decision 5 — Server-owned Store pricing pipeline

**Decision**: Add a Store pricing orchestrator that reads authoritative retail/wholesale or variant
prices, then applies eligible promotion/coupon rules. It supplies trusted snapshots to the existing
SalesOrder flow.

**Rationale**: `SalesOrderService::calculateTotals()` correctly enforces a single total formula but
currently accepts item unit prices and order discount from its caller. The legacy Store controller
also consumes request prices. Store-origin calls therefore need a stricter trusted boundary without
breaking Admin negotiated-order behavior.

**Alternatives considered**: Adding a listing price column was rejected by V1 scope. Globally changing
Admin order pricing was rejected as an unrelated compatibility break.

## Decision 6 — Extend existing SalesOrder lifecycle

**Decision**: The Store adapter calls `SalesOrderService` and existing stock/fulfillment services;
`sales_orders` gains origin, origin actor, and client request id only.

**Rationale**: Existing services already own serials, reservations, conflicts, status logs, daily
sessions, initial payments, dispatch, delivery, Shiply, cancellation/reversal, and notifications.
The legacy controller bypasses these safeguards, which is the primary integration risk.

**Alternatives considered**: Keeping direct Store inserts was rejected as unsafe. A second Store order
service with independent lifecycle was rejected as duplicate authority.

Historical origin is not inferred: the project owner confirmed every existing production
`sales_orders` row is Admin-origin. The additive migration therefore backfills every existing row to
`origin=admin`; new Admin writes set `admin`, and accepted Store writes set `store`. The value remains
an extensible bounded string for future origins, but V1 reporting requires only admin/store.

## Decision 7 — Explicit account links

**Decision**: Link shared `users` to existing customers and/or sellers in
`online_store_account_links`; do not add `user_id` directly to both party tables in V1.

**Rationale**: The dump shows Store and staff identities share `users`, but neither `customers` nor
`sellers` has a user relation. A link table supports customer, supplier, or both, preserves provenance,
and permits controlled conflict resolution.

**Alternatives considered**: Email/phone inference was rejected for ownership. Direct nullable columns
on both party tables were rejected because they cannot express link state/source/history cleanly.

## Decision 8 — Ledger-derived credit with party-aware extensions

**Decision**: Credit policy stores eligibility and optional limits only. Extend sales-order ledger and
accounting projection paths to support `partner_type=customer|seller` consistently.

**Rationale**: `debt_transactions` and accounting journal lines already support either customer or
seller. However, `DebtLedgerService::syncSalesOrderToLedger()` currently forces `sellerId=null`, and
sales-order accounting projection uses customer fields. These are verified implementation gaps.

**Alternatives considered**: A Store wallet/current-balance column was rejected because balances must
come from the ledger. Modeling supplier purchases outside SalesOrder was rejected by the approved spec.

## Decision 9 — Transactional coupon redemption

**Decision**: Keep coupon configuration, targeting, and redemption evidence in dedicated tables;
lock the coupon/usage scope and use unique order redemption plus the order client request id.

**Rationale**: No coupon/promotion tables exist in the dump. Limits must hold under concurrency, and
accepted-order attribution must remain queryable for reports and reversals.

**Alternatives considered**: Counters only on coupon rows were rejected because per-user attribution,
reconciliation, and cancellation handling would be unreliable.

Promotions also carry explicit `scope=global|targeted`. Global promotions have no target rows;
targeted promotions require at least one valid allow-listed listing/category target before activation.
Missing target rows never imply global scope.

## Decision 10 — Store-specific audit table

**Decision**: Add `online_store_audit_events` for sensitive Store management changes only.

**Rationale**: The dump has several domain-specific audit/activity tables but no generic application
audit table suitable for listings, promotions, coupons, settings, links, and reviews. Base Product
price auditing stays with the pricing/inventory domain.

**Alternatives considered**: Reusing employee permission, debt, notification-policy, or purchase audit
tables was rejected because their schemas and semantics belong to those domains.

## Decision 11 — Typed Store settings table

**Decision**: Use a singleton `online_store_settings` row with explicit operating fields and JSON
translation maps for policy content.

**Rationale**: `app_settings` exists but the dump contains no Store setting rows, and free-form key/value
storage cannot enforce coupled invariants or atomically audit a coherent Store configuration.

**Alternatives considered**: Adding many `store_*` keys to `app_settings` was rejected due to weak
validation and scattered audit state. A settings-per-key Store table was rejected for the same reason.

## Decision 12 — Compatibility adapter, not Store V2

**Decision**: Freeze existing `api_store.php` contracts and incrementally delegate compatible behavior
to new shared services behind the same paths.

**Rationale**: Current clients depend on ASP.NET-shaped field names and response envelopes. The feature
explicitly excludes a V2 migration.

**Alternatives considered**: Immediate endpoint replacement/versioning was rejected as out of scope.

## Decision 13 — Actor-scoped checkout idempotency and bounded legacy mitigation

**Decision**: Native checkout requires a stable client request ID and resolves uniqueness/lookup by
`(origin, origin_user_id, client_request_id)`. The authenticated actor is checked before returning an
existing order and idempotency lookup occurs before any side effect.

The current Flutter Store checkout sends no stable request/attempt ID. Strict retry idempotency is
therefore impossible for unchanged legacy clients: an identical later order may be an intentional new
purchase. The compatibility adapter derives a short-lived fingerprint from a protocol-version
constant, authenticated User ID, canonical Product/variant quantities, coupon code, and canonical
delivery city/village/address/payment intent. Under an actor-scoped lock, an accepted matching request
within the documented two-minute compatibility window returns the same order through a dedicated
expiring compatibility-attempt row; the accepted SalesOrder receives a separate server UUID. After
expiry, the locked row is replaced and the same payload is a new attempt. Price/discount/total/
timestamp and request-supplied user ID are excluded.
This prevents concurrent/near retry duplication but is explicitly not a permanent retry guarantee.
The migration path is to add a generated UUID per checkout attempt in the Store client; the native
contract then supplies full FR-031 behavior.

**Rationale**: The observed request contains cart, address, mutable client totals, timestamps, and
`userAddId`, but no attempt identity. Actor scope prevents any cross-account match or disclosure.

**Alternatives considered**: Hashing only total/timestamp was rejected as mutable and collision-prone;
indefinite payload hashing was rejected because it blocks legitimate repeat purchases.

## Decision 14 — Password-reset security requires a Store client migration

**Decision**: Choose compatibility outcome B. The current Flutter client requires `email`, `userId`,
and `otp` in `ForgotPassword`, compares OTP only on-device, then calls `ChangePasswordToForgot` with
`userId` and new passwords but no server-verifiable proof. A secure server-only correction cannot keep
that successful flow working unchanged.

The minimum client migration removes the required response `otp`, submits email plus OTP to a server
verification endpoint, receives an opaque short-lived single-use reset proof, and submits that proof
with the new password. Server storage uses a hashed OTP with expiry, attempt/rate limits, single-use
state, generic forgot-password responses to reduce enumeration, and account-bound verification. OTP,
passwords, and reset proofs are never logged or returned. Deployment must keep insecure reset disabled
until a compatible client release is adopted; OTP leakage is never retained for compatibility.

**Rationale**: Backend and Flutter inspection confirms the existing flow has no server-side proof
between OTP entry and reset and permits reset by known User ID.

**Alternatives considered**: Preserving returned OTP or accepting `userId` alone was rejected as an
account-takeover vulnerability. A broader authentication redesign remains out of scope.

## Decision 15 — Typed home-section composition

**Decision**: Manual product/category sections use allow-listed typed targets: `listing` or `category`.
Product-like manual sections (`best_sellers`, `recent`, `offers`, `custom`) reference listings;
`categories` references online categories. Automatic categories/product sections use validated
`selection_config` and no manual rows. `hero` uses ordered dedicated banner relationships and no
generic item rows. `maintenance` uses validated `selection_config` for the existing maintenance
destination/content integration and no listing/category row. Invalid type/section combinations fail.

**Rationale**: This supports all approved section types without copying categories or accepting ORM
class names. Stable `sort_order`, then item ID, defines deterministic ordering; invisible/ineligible
targets are omitted without changing stored curation.

**Alternatives considered**: Listing-only rows cannot represent categories; arbitrary polymorphic
classes were rejected as unsafe; treating banners as generic rows duplicates banner scheduling.

## Verified dump findings

- No table name begins with `online_store`; no coupon, promotion, banner, review, account-link, or
  Store-audit table exists.
- `products` contains authoritative multilingual names/descriptions, `normailPrice`,
  `wholesalePrice`, stock, flags, and a physical `store_section_id`.
- Product media uses production columns `itemId` and `imageUrl`, with no persistent main/order fields;
  sizes/colors use `itemId`/`sizeId`.
- `sales_orders` already contains partner identity, totals, payment, stock/financial timestamps,
  delivery/Shiply address fields, and audit actors, but no origin or request-id columns.
- `sales_order_settlements.idempotency_key` is nullable and indexed uniquely; it already protects
  payment-side retries.
- Debt transactions support exactly one of customer/seller and source/source_id attribution.
- Accounting journal lines support customer and seller dimensions; source keys are unique.
- Permission/employee-permission tables and `CheckPermission` provide the existing authorization model.
- Notification templates and Admin/FCM infrastructure exist; reviews and customer marketing delivery
  need new Store orchestration, not a parallel FCM stack.
- `app_settings` exists, but the snapshot has no Store operating-setting rows used by the compatibility
  controller's optional keys.

## Schema/code discrepancies to preserve in planning

1. Old media migrations create `product_id`/`image_url`, while the dump and current models/controllers
   use `itemId`/`imageUrl`. New FKs and adapters must target verified production names, and migrations
   must be tested against both a fresh migration build and a dump-derived schema.
2. Setup reports the Spec Kit feature label `001-online-store-management`, while the actual Git branch
   is `feat/online-store-management`; documentation uses the actual Git branch.
3. Legacy Store model classes alias core tables. They are compatibility projections, not separate data.
4. Store-origin is not a reliable historical field. The owner-confirmed production rule is that all
   pre-migration `sales_orders` are Admin-origin, so the backfill sets all existing rows to `admin`
   without inspecting serial format, notes, or UI metadata.
5. `SalesOrderService` accepts caller-supplied unit price/discount for Admin flexibility; Store origin
   needs a server-priced adapter rather than assuming the core method is already Store-safe.
6. Sales-order debt/accounting code is customer-oriented even though order partner fields and ledgers
   support sellers. Seller-credit tests are mandatory before rollout.

## Resolved clarifications

No product/domain design clarification remains. Business choices are fixed by the approved spec: no
Store base-price override, non-stacking discounts by default, visible/non-purchasable zero stock, and
explicit credit eligibility for approved customers or suppliers with ledger-derived balances. The
only rollout dependency is a Store client update before the insecure forgot-password/reset flow can
be replaced; legacy checkout has a documented bounded mitigation until that client also emits a
stable request ID.
