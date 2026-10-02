---

description: "Dependency-ordered implementation tasks for Online Store Management"
---

# Tasks: Online Store Management

**Input**: Design documents from `specs/001-online-store-management/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/`, `quickstart.md`, and `.specify/memory/constitution.md`

**Tests**: Tests are required because the specification and constitution require regression evidence for authorization, compatibility, stock, order, debt, accounting, and concurrency behavior. Database-backed tests MUST use an isolated disposable schema and MUST NOT modify `DB/dr_bike.sql` or the developer database.

**Organization**: Tasks are grouped by user story. P1 stories are ordered before P2/P3 stories, while dependencies and safe parallel opportunities are stated explicitly.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it targets different files and has no dependency on another incomplete task in the same phase.
- **[Story]**: Maps the task to a numbered user story in `spec.md`.
- Every task names the intended file path and concrete outcome.

## Phase 1: Setup and Safety Baseline

**Purpose**: Establish implementation locations, isolated test safeguards, and frozen compatibility evidence before behavior changes.

- [X] T001 Create the Online Store application namespaces and shared directory structure in `app/Models/OnlineStore/.gitkeep`, `app/Services/OnlineStore/.gitkeep`, `app/Http/Controllers/API/OnlineStore/.gitkeep`, `app/Http/Requests/OnlineStore/.gitkeep`, and `app/Policies/OnlineStore/.gitkeep`
- [X] T002 [P] Create Online Store unit/feature test directories and a reusable fixture builder skeleton in `tests/Unit/OnlineStore/.gitkeep`, `tests/Feature/OnlineStore/.gitkeep`, and `tests/Support/OnlineStoreFixtureFactory.php`
- [X] T003 Add a fail-closed disposable-database guard for Online Store database tests in `tests/Support/RequiresDisposableDatabase.php` and register it in `tests/TestCase.php` without changing production database configuration
- [X] T004 Capture sanitized pre-change `routes/api_store.php` request/response fixture shapes for auth, catalog, orders, settings, comments, cities, and notifications in `tests/Fixtures/OnlineStore/legacy_api_store_contracts.php`
- [X] T005 [P] Add reusable authenticated Store/Admin actor, customer, seller, Product/variant, stock, and ledger fixture helpers in `tests/Support/OnlineStoreFixtureFactory.php`
- [X] T006 Document the test database, queue fakes, Shiply/FCM sandbox boundaries, and no-production-dump-write rule in `specs/001-online-store-management/quickstart.md`

**Checkpoint**: Compatibility evidence and safe test infrastructure exist before schema or behavior changes.

---

## Phase 2: Foundational Schema and Shared Boundaries

**Purpose**: Add compatibility-safe persistence, shared value rules, permissions, and protected routing required by every story.

**CRITICAL**: Complete this phase before starting user-story implementation.

- [X] T007 Add `sales_orders.origin` (nullable then backfill every existing row to `admin`, then non-null/default `admin`), nullable `origin_user_id`, nullable `client_request_id`, index `(origin,created_at)`, and unique `(origin,origin_user_id,client_request_id)` in `database/migrations/2026_10_01_000001_add_online_store_origin_to_sales_orders.php`; do not infer origin from serials and do not require request IDs for Admin rows
- [X] T008 Update repository-appropriate fillable/guarded handling, casts where applicable, and the origin User relation for `origin`, `origin_user_id`, and `client_request_id` in `app/Models/SalesOrder.php`; preserve existing Admin semantics and never permit clients to author trusted origin fields directly
- [X] T009 [P] Create `online_store_listings`, `online_store_categories`, `online_store_category_listing`, and `online_store_media_presentations` with the exact FKs, unique keys, lifecycle values, nullable translation fields, boolean defaults, and indexes from `data-model.md` in `database/migrations/2026_10_01_000002_create_online_store_catalog_tables.php`
- [X] T010 [P] Create `online_store_promotions`, `online_store_promotion_targets`, `online_store_coupons`, `online_store_coupon_targets`, and `online_store_coupon_redemptions` with `decimal(14,2)` money, explicit `scope=global|targeted`, allow-listed `listing|category` targets, unique coupon code/order redemption constraints, and usage indexes in `database/migrations/2026_10_01_000003_create_online_store_discount_tables.php`
- [X] T011 [P] Create `online_store_home_sections`, `online_store_home_section_items`, and `online_store_banners` with allow-listed section/target/action values, typed targets, deterministic sort indexes, date windows, and no arbitrary polymorphic class column in `database/migrations/2026_10_01_000004_create_online_store_content_tables.php`
- [X] T012 [P] Create `online_store_account_links`, `online_store_credit_policies`, `online_store_reviews`, `online_store_settings`, `online_store_audit_events`, and expiring `online_store_legacy_checkout_attempts` with the exact uniqueness, party-role, history-preservation, decimal, and actor constraints from `data-model.md` in `database/migrations/2026_10_01_000005_create_online_store_account_governance_tables.php`
- [X] T013 Create bounded constants and validation rules for listing states, section modes/types, typed targets, promotion/coupon scope and discount types, account roles/statuses, review states, origins, and audit entity/action types in `app/Support/OnlineStore/OnlineStoreValues.php`
- [X] T014 Implement the server-controlled typed-target registry and section/target compatibility matrix in `app/Support/OnlineStore/OnlineStoreTargetRegistry.php`
- [X] T015 Add authenticated Online Store Admin route grouping with Sanctum/current refresh middleware and resource-level permission hooks in `routes/api.php` without renaming or removing anything in `routes/api_store.php`
- [X] T016 Seed the seven permissions `Online Store View`, `Online Store Products Manage`, `Online Store Categories Manage`, `Online Store Content Manage`, `Online Store Promotions Manage`, `Online Store Reviews Manage`, and `Online Store Settings Manage` idempotently in `database/seeders/OnlineStorePermissionSeeder.php` and invoke it from `database/seeders/DatabaseSeeder.php`
- [X] T017 [P] Add migration tests for fresh and dump-derived schemas, all-admin historical origin backfill, FK/index/unique constraints, nullable Admin request IDs, cross-user duplicate request IDs, and rollback preservation in `tests/Feature/OnlineStore/OnlineStoreSchemaMigrationTest.php`
- [X] T018 [P] Add architecture regression tests proving no `online_store_orders`, Store stock balance, Store debt balance, listing price override, or repurposed `store_sections` authority exists in `tests/Unit/OnlineStore/OnlineStoreDomainBoundaryTest.php`
- [X] T019 Add shared authorization/404-on-inaccessible behavior for Online Store resources in `app/Policies/OnlineStore/OnlineStorePolicy.php` and reusable request ownership checks in `app/Http/Requests/OnlineStore/AuthorizesOnlineStoreResource.php`

**Checkpoint**: Additive schema, origin backfill, shared value rules, protected route boundary, and permissions are ready.

---

## Phase 3: User Story 1 - Curate Inventory Products for the Store (Priority: P1) — MVP

**Goal**: Create exactly one lifecycle-managed Store listing per existing Product and publish it only when complete.

**Independent Test**: Select one Product, create its only listing, complete and publish it, hide/reopen the same row, and prove incomplete or duplicate publication is rejected without changing Product data.

### Tests for User Story 1

- [x] T020 [P] [US1] Add model/constraint tests for unique `product_id`, states `draft|ready|published|hidden`, default `readiness_state=incomplete`, nullable translation/badge fields, boolean merchandising defaults, and no price/stock fields in `tests/Unit/OnlineStore/OnlineStoreListingTest.php`
- [x] T021 [P] [US1] Add listing API contract, exact-permission, lifecycle, duplicate-concurrency, completeness, and direct-request authorization tests in `tests/Feature/OnlineStore/ListingManagementTest.php`

### Implementation for User Story 1

- [x] T022 [P] [US1] Implement `OnlineStoreListing` with Product relation, JSON translations/issues, boolean casts, lifecycle timestamps, and unique one-row-per-Product semantics in `app/Models/OnlineStore/OnlineStoreListing.php`
- [x] T023 [P] [US1] Implement listing create/update/transition validation that accepts no base-price or stock mutation fields in `app/Http/Requests/OnlineStore/ManageListingRequest.php`
- [x] T024 [US1] Implement deterministic completeness evaluation for valid Product, display name, description, usable main media, active category, and authoritative price in `app/Services/OnlineStore/ListingReadinessService.php`
- [x] T025 [US1] Implement allowed transitions on the same row, publication blocking, readiness refresh, and published/hidden timestamps in `app/Services/OnlineStore/ListingLifecycleService.php`
- [x] T026 [US1] Implement Admin listing CRUD/readiness/transition endpoints with `Online Store Products Manage` enforcement in `app/Http/Controllers/API/OnlineStore/ListingController.php`
- [x] T027 [US1] Register listing/readiness/transition routes under the authenticated group in `routes/api.php`
- [x] T028 [US1] Serialize listing lifecycle, translations, merchandising flags, read-only authoritative base prices/availability, and readiness issues in `app/Http/Resources/OnlineStore/OnlineStoreListingResource.php`
- [x] T029 [US1] Integrate publication lifecycle audit calls without auditing Product base-price changes in `app/Services/OnlineStore/ListingLifecycleService.php`

**Checkpoint**: US1 independently delivers the publishable listing lifecycle and is the suggested MVP.

---

## Phase 4: User Story 2 - Organize Storefront Discovery (Priority: P1)

**Goal**: Provide independent online categories, deterministic home sections, and scheduled banners without changing inventory taxonomy or physical locations.

**Independent Test**: Build a category hierarchy, manually order category/listing sections, configure automatic and maintenance sections, add hero banners, and verify deterministic customer visibility without touching inventory categories or `store_sections`.

### Tests for User Story 2

- [x] T030 [P] [US2] Add category hierarchy, cycle prevention, membership, inactive-category, sibling ordering, and no-`store_sections` mutation tests in `tests/Feature/OnlineStore/OnlineStoreCategoryTest.php`
- [x] T031 [P] [US2] Add home-section typed-target matrix tests covering manual listing/category items, automatic allow-listed config, hero banners, maintenance config, invalid class/type rejection, and `sort_order,id` ties in `tests/Feature/OnlineStore/HomeSectionTest.php`
- [x] T032 [P] [US2] Add banner schedule/timezone, safe URL scheme, target existence, action compatibility, and inactive-window tests in `tests/Feature/OnlineStore/BannerTest.php`

### Implementation for User Story 2

- [x] T033 [P] [US2] Implement `OnlineStoreCategory` and `OnlineStoreCategoryListing` with JSON translations, self-parent relation, active/home flags, sibling/category ordering, and unique membership in `app/Models/OnlineStore/OnlineStoreCategory.php` and `app/Models/OnlineStore/OnlineStoreCategoryListing.php`
- [x] T034 [P] [US2] Implement `OnlineStoreHomeSection` and typed `OnlineStoreHomeSectionItem` with modes `manual|automatic|dedicated_banners`, allow-listed `listing|category` targets, and deterministic ordering in `app/Models/OnlineStore/OnlineStoreHomeSection.php` and `app/Models/OnlineStore/OnlineStoreHomeSectionItem.php`
- [x] T035 [P] [US2] Implement `OnlineStoreBanner` with multilingual content, schedule, sort, and actions `listing|category|promotion|url|none` in `app/Models/OnlineStore/OnlineStoreBanner.php`
- [x] T036 [US2] Implement category cycle checks, membership replacement, active-state consequences, and complete sibling reorder transactions in `app/Services/OnlineStore/OnlineStoreCategoryService.php`
- [x] T037 [US2] Implement section composition rules: hero uses dedicated banners; categories uses category items/config; product sections use listing items/config; maintenance uses validated config; automatic selectors accept no SQL/class/query fragments in `app/Services/OnlineStore/HomeSectionService.php`
- [x] T038 [US2] Implement banner target/action validation, safe URL validation, business-timezone scheduling, and deterministic order in `app/Services/OnlineStore/BannerService.php`
- [x] T039 [US2] Implement category, home-section, and banner management endpoints with exact `Online Store Categories Manage` and `Online Store Content Manage` permissions in `app/Http/Controllers/API/OnlineStore/StorefrontContentController.php`
- [x] T040 [US2] Implement storefront composition reads that omit ineligible targets without mutating curation and expose deterministic section/banner results in `app/Http/Controllers/API/Store/StoreHomeController.php`
- [x] T041 [US2] Register management endpoints in `routes/api.php` and compatibility-safe storefront reads in `routes/api_store.php`

**Checkpoint**: US2 independently supplies Store discovery/content while preserving physical inventory taxonomy.

---

## Phase 5: User Story 3 - Present Accurate Media, Price, and Stock (Priority: P1)

**Goal**: Initialize and manage Store-only media presentation while always deriving price and availability from authoritative Product/variant and stock domains.

**Independent Test**: Create a listing with mixed source media, verify View→Normal→3D/source-ID initialization and default main image, reorder/hide/change main, mutate authoritative price/stock, and prove source media and base prices were not changed.

### Tests for User Story 3

- [X] T042 [P] [US3] Add media initialization tests for all valid source rows ordered `view_image`, `normal_image`, `image3d`, then source ID, with first valid row main and publication blocked when no usable visible main exists in `tests/Feature/OnlineStore/MediaPresentationTest.php`
- [X] T043 [P] [US3] Add presentation validation tests for one visible main, source ownership, exceptional Store media exclusivity, supported metadata, source deletion, independent reorder/hide, and zero source mutation in `tests/Unit/OnlineStore/MediaPresentationServiceTest.php`
- [X] T044 [P] [US3] Add authoritative retail/wholesale/variant price and stock-read tests, falsified client-value rejection, mixed variants, and visible/non-purchasable zero-stock behavior in `tests/Feature/OnlineStore/StoreCatalogAuthorityTest.php`

### Implementation for User Story 3

- [X] T045 [P] [US3] Implement `OnlineStoreMediaPresentation` with allow-listed `normal_image|image3d|view_image|variant|store_specific`, nullable `source_id`, mutually exclusive `store_media_path`, visibility/main flags, metadata, and stable Store order in `app/Models/OnlineStore/OnlineStoreMediaPresentation.php`
- [X] T046 [US3] Implement first-listing media initialization using valid View rows then Normal rows then 3D rows, each by source ID, assigning sequential Store order and first valid main without reading nonexistent inventory order/main fields in `app/Services/OnlineStore/MediaPresentationService.php`
- [X] T047 [US3] Implement transactional Store-only replace/reorder/hide/main validation that proves source ownership and never updates/deletes authoritative media rows in `app/Services/OnlineStore/MediaPresentationService.php`
- [X] T048 [US3] Implement read-only authoritative retail/wholesale/variant price resolution with no listing override in `app/Services/OnlineStore/StorePriceResolver.php`
- [X] T049 [US3] Implement Product/variant availability reads through existing reservation semantics and visible/non-purchasable zero-stock output in `app/Services/OnlineStore/StoreAvailabilityService.php`
- [X] T050 [US3] Implement Admin media presentation/readiness endpoints and validation in `app/Http/Controllers/API/OnlineStore/ListingMediaController.php` and `app/Http/Requests/OnlineStore/ReplaceListingMediaRequest.php`
- [X] T051 [US3] Update Store listing/catalog serialization to expose deterministic presentation, read-only base/final price inputs, and availability in `app/Http/Resources/OnlineStore/StorefrontListingResource.php`

**Checkpoint**: US3 independently proves accurate media, price, and stock presentation with no competing authority.

---

## Phase 6: User Story 5 - Manage Store Orders and Linked Identities (Priority: P1)

**Goal**: Link Store users to existing business parties and create Store orders through the authoritative SalesOrder lifecycle with actor-scoped native idempotency and bounded legacy mitigation.

**Independent Test**: Link one User to customer/seller roles, create Admin and Store orders, retry native and legacy submissions, exercise history/cancel ownership, and prove origin, side effects, and cross-account isolation.

### Tests for User Story 5

- [ ] T052 [P] [US5] Add account-link role/FK/status/source, duplicate/conflict, blocked-user, approval, and cross-account IDOR tests in `tests/Feature/OnlineStore/AccountLinkTest.php`
- [ ] T053 [P] [US5] Add origin tests proving all historical rows backfill to `admin`, new Admin=`admin`, new Store=`store`, no serial inference, and authorized linked history across origins in `tests/Feature/OnlineStore/SalesOrderOriginTest.php`
- [ ] T054 [P] [US5] Add native checkout concurrency tests for unique `(origin,origin_user_id,client_request_id)`, same-actor replay, cross-actor same-ID isolation, lookup-before-side-effects, and exactly-once SalesOrder/items, stock reservation, status-log, and notification effects in `tests/Feature/OnlineStore/CheckoutIdempotencyTest.php`; cover existing cash settlement/accounting only when the baseline SalesOrder lifecycle invokes them, and defer coupon and credit/debt assertions to US4 and US6
- [ ] T055 [P] [US5] Add legacy checkout tests for the exact current request shape, ignored client price/total/user IDs, canonical actor fingerprint, two-minute dedupe, post-window intentional repeat, and no claim of indefinite idempotency in `tests/Feature/OnlineStore/LegacyCheckoutCompatibilityTest.php`
- [ ] T056 [P] [US5] Add Store order history/cancel authorization, lifecycle delegation, status-log, delivery, Shiply, and 404-on-cross-account regression tests in `tests/Feature/OnlineStore/StoreOrderOwnershipTest.php`

### Implementation for User Story 5

- [ ] T057 [P] [US5] Implement `OnlineStoreAccountLink` with exactly one role-matching party FK, roles `customer|seller`, sources `store_app|admin_app|import`, statuses `pending|active|suspended`, unique `(user_id,role)`, and verified attribution in `app/Models/OnlineStore/OnlineStoreAccountLink.php`
- [ ] T058 [P] [US5] Implement `OnlineStoreLegacyCheckoutAttempt` with unique `(origin_user_id,request_fingerprint)`, nullable accepted SalesOrder, expiry, and cleanup scope in `app/Models/OnlineStore/OnlineStoreLegacyCheckoutAttempt.php`
- [ ] T059 [US5] Implement explicit user-to-customer/seller linking, conflict detection, verification, and party ownership resolution without email/phone inference in `app/Services/OnlineStore/StoreIdentityService.php`
- [ ] T060 [US5] Extend SalesOrder creation inputs to accept trusted `origin`, `origin_user_id`, and nullable actor-scoped `client_request_id` while preserving existing Admin callers in `app/Services/SalesOrderService.php`
- [ ] T061 [US5] Implement native idempotency lookup/lock before every stock, coupon, payment, debt, accounting, notification, and status effect in `app/Services/OnlineStore/StoreCheckoutIdempotencyService.php`
- [ ] T062 [US5] Implement legacy canonical fingerprinting from protocol version, authenticated User, sorted Product/variant quantities, normalized coupon, canonical delivery city/village/address, and payment intent—excluding totals, prices, timestamps, and submitted identity—in `app/Services/OnlineStore/LegacyCheckoutDeduplicationService.php`
- [ ] T063 [US5] Implement the server-priced Store checkout command that resolves linked party, listings/variants, authoritative price, availability, delivery, and origin metadata before delegating to `SalesOrderService` in `app/Services/OnlineStore/OnlineStoreCheckoutService.php`
- [ ] T064 [US5] Refactor legacy `ManageOrder` to authenticate bearer identity, ignore client monetary/identity authority, use bounded dedupe and `OnlineStoreCheckoutService`, and preserve the accepted legacy response shape in `app/Http/Controllers/API/Store/StoreOrdersController.php`
- [ ] T065 [US5] Refactor legacy order history/cancel to derive ownership from the authenticated account link and invoke existing cancellation lifecycle rather than direct status writes in `app/Http/Controllers/API/Store/StoreOrdersController.php`
- [ ] T066 [US5] Implement account-link Admin endpoints and exact `Online Store Settings Manage` permission checks in `app/Http/Controllers/API/OnlineStore/AccountLinkController.php` and `app/Http/Requests/OnlineStore/ManageAccountLinkRequest.php`
- [ ] T067 [US5] Register native Store checkout and account-link management routes without removing legacy paths in `routes/api.php` and `routes/api_store.php`
- [ ] T068 [US5] Add scheduled cleanup for expired compatibility-attempt rows without deleting SalesOrders in `app/Console/Commands/PurgeExpiredOnlineStoreCheckoutAttempts.php` and `app/Console/Kernel.php`

**Checkpoint**: US5 provides one identity/order authority, explicit origin, native full idempotency, and accurately bounded legacy compatibility.

---

## Phase 7: User Story 4 - Run Promotions and Coupons Safely (Priority: P2)

**Goal**: Apply deterministic Store discounts through promotions/coupons only, with explicit targeting and concurrency-safe redemption.

**Independent Test**: Configure global and targeted promotions plus a limited coupon, preview and place eligible/ineligible orders, race the last use, and reconcile exactly one winning discount/redemption without changing base prices.

### Tests for User Story 4

- [ ] T069 [P] [US4] Add promotion tests for percentage/fixed positive value, max 100 percent, retail/wholesale/both, inclusive valid dates, explicit `global|targeted` scope, global zero rows, targeted minimum one valid target, and deterministic priority in `tests/Feature/OnlineStore/PromotionTest.php`
- [ ] T070 [P] [US4] Add coupon tests for case-insensitive unique code, positive discount, minimum order, account/price applicability, global/per-user limits, listing/category targets, and non-negative outcome in `tests/Feature/OnlineStore/CouponTest.php`

### Implementation for User Story 4

- [ ] T071 [P] [US4] Implement Promotion and PromotionTarget models with explicit `scope=global|targeted`, allow-listed `listing|category`, `decimal(14,2)` positive discount, applicability, dates, priority, and actor relations in `app/Models/OnlineStore/OnlineStorePromotion.php` and `app/Models/OnlineStore/OnlineStorePromotionTarget.php`
- [ ] T072 [P] [US4] Implement Coupon, CouponTarget, and CouponRedemption models with normalized unique code, limits, party attribution, statuses `reserved|applied|released`, unique SalesOrder, and usage indexes in `app/Models/OnlineStore/OnlineStoreCoupon.php`, `app/Models/OnlineStore/OnlineStoreCouponTarget.php`, and `app/Models/OnlineStore/OnlineStoreCouponRedemption.php`
- [ ] T073 [US4] Implement promotion activation/eligibility and deterministic non-stacking winning-rule selection, rejecting targeted activation with zero valid targets and global promotions with rows in `app/Services/OnlineStore/PromotionService.php`
- [ ] T074 [US4] Implement coupon validation and transactional locked redemption limits tied to authenticated user/party and accepted SalesOrder in `app/Services/OnlineStore/CouponService.php`
- [ ] T075 [US4] Implement the pricing pipeline that reads `StorePriceResolver`, applies the winning promotion and optional coupon, prevents negative discounts, and returns trusted snapshots in `app/Services/OnlineStore/StorePricingService.php`
- [ ] T076 [US4] Implement promotion/coupon CRUD, activation, redemption inspection, and pricing-preview endpoints with `Online Store Promotions Manage` permission in `app/Http/Controllers/API/OnlineStore/PromotionController.php` and `app/Http/Controllers/API/OnlineStore/CouponController.php`
- [ ] T077 [US4] Integrate `StorePricingService` and coupon reservation into the pre-effect checkout transaction in `app/Services/OnlineStore/OnlineStoreCheckoutService.php`
- [ ] T078 [US4] After CouponService and StorePricingService checkout integration, add same-actor native retry, concurrent final-use reservation, exactly-once coupon redemption per accepted order, rollback, release/reversal policy, and reporting evidence tests in `tests/Feature/OnlineStore/CouponRedemptionConcurrencyTest.php`

**Checkpoint**: US4 independently delivers safe Store discounts without base-price ownership.

---

## Phase 8: User Story 6 - Purchase on Approved Credit (Priority: P2)

**Goal**: Permit explicitly eligible customer or supplier accounts to use full/partial credit while deriving debt and available credit from existing ledgers.

**Independent Test**: For eligible customer and supplier roles, accept a 2,000 order with 500 paid and reconcile exactly 500 settlement plus 1,500 authoritative ledger debt; reject ineligible/over-limit races without partial effects.

### Tests for User Story 6

- [ ] T079 [P] [US6] Add credit-policy validation tests for active verified link, explicit eligibility, optional non-negative `decimal(14,2)` limit, bounded currency, approval/expiry, and no persisted current/available balance in `tests/Unit/OnlineStore/OnlineStoreCreditPolicyTest.php`
- [ ] T080 [P] [US6] Add customer and supplier 2,000/500/1,500 partial-payment reconciliation tests across settlement, debt transaction, accounting lines, source keys, and cancellation reversal in `tests/Feature/OnlineStore/StoreCreditCheckoutTest.php`
- [ ] T081 [P] [US6] Add ineligible, expired, over-limit, currency mismatch, concurrent limit consumption, stock failure, and rollback-with-no-partial-effects tests in `tests/Feature/OnlineStore/StoreCreditConcurrencyTest.php`

### Implementation for User Story 6

- [ ] T082 [P] [US6] Implement `OnlineStoreCreditPolicy` with unique account link, explicit `is_eligible=false`, nullable non-negative `credit_limit decimal(14,2)`, currency, approval, expiry, and no balance columns in `app/Models/OnlineStore/OnlineStoreCreditPolicy.php`
- [ ] T083 [US6] Implement party-aware ledger exposure/current debt and available credit calculation with policy locking in `app/Services/OnlineStore/StoreCreditService.php`
- [ ] T084 [US6] Extend sales-order debt synchronization to use exactly one validated customer or seller dimension and post only the unpaid remainder idempotently in `app/Services/DebtLedgerService.php`
- [ ] T085 [US6] Extend SalesOrder accounting projections, settlements, metadata, and reversals to use the same customer/seller dimension while retaining unique source-key idempotency in `app/Services/AccountingProjectionService.php`
- [ ] T086 [US6] Integrate locked eligibility/limit checks, existing initial payment/settlement flow, and unpaid ledger posting into Store checkout without adding payment/debt tables in `app/Services/OnlineStore/OnlineStoreCheckoutService.php`
- [ ] T087 [US6] Implement credit-policy management and ledger-derived credit read endpoints with `Online Store Settings Manage` permission in `app/Http/Controllers/API/OnlineStore/CreditPolicyController.php`
- [ ] T088 [US6] Register credit-policy and credit-summary routes in `routes/api.php`

**Checkpoint**: US6 proves customer/supplier credit through existing settlement, debt, and accounting authorities.

---

## Phase 9: User Story 8 - Govern and Measure the Store (Priority: P2)

**Goal**: Enforce Store settings/permissions/audit and provide reconciled dashboard/report outcomes.

**Independent Test**: Exercise every Admin capability under its exact permission, change operating and audited state, and reconcile dashboard/reports to listings, explicit origins, discounts, stock, reviews, and ledger records.

### Tests for User Story 8

- [ ] T089 [P] [US8] Add Store settings singleton/invariant and precedence tests for disabled store, maintenance, checkout, COD, guest browsing, minimum order, languages, policies, and fixed `visible_non_purchasable` V1 behavior in `tests/Feature/OnlineStore/OnlineStoreSettingsTest.php`
- [ ] T090 [P] [US8] Add a permission matrix test for all seven capabilities, Admin bypass, employee direct URL denial, inaccessible 404 behavior, and no UI-only authorization assumptions in `tests/Feature/OnlineStore/OnlineStorePermissionTest.php`
- [ ] T091 [P] [US8] Add audit tests for publication, promotions, coupons, settings, identity links, review moderation, and other Store pricing outcomes with actor/action/entity/time/before-after data and secret/PII redaction in `tests/Feature/OnlineStore/OnlineStoreAuditTest.php`
- [ ] T092 [P] [US8] Add dashboard/report reconciliation tests for explicit admin/store origins, listing states, stock, promotions, coupons, pending reviews, retail/wholesale, AOV, and Store debt activity with filters/timezone and unavailable-not-zero semantics in `tests/Feature/OnlineStore/OnlineStoreReportTest.php`; seed review-table evidence through the isolated database fixture rather than depending on the later US7 model/service, then repeat the integrated assertion in final regression
- [ ] T093 [P] [US8] Add 10,000-listing query-budget and 95th-percentile two-second acceptance harness in `tests/Feature/OnlineStore/OnlineStorePerformanceTest.php`

### Implementation for User Story 8

- [ ] T094 [P] [US8] Implement singleton `OnlineStoreSettings` with the exact fields and typed JSON/money/boolean casts from `data-model.md` in `app/Models/OnlineStore/OnlineStoreSettings.php`
- [ ] T095 [P] [US8] Implement `OnlineStoreAuditEvent` with allow-listed entity/action, actor, before/after, correlation, IP, timestamp, indexes, and secret redaction in `app/Models/OnlineStore/OnlineStoreAuditEvent.php`
- [ ] T096 [US8] Implement typed setting validation and operating-state precedence in `app/Services/OnlineStore/OnlineStoreSettingsService.php`
- [ ] T097 [US8] Implement transaction-coupled Store audit recording and redaction, leaving Product base-price auditing in the inventory/pricing domain in `app/Services/OnlineStore/OnlineStoreAuditService.php`
- [ ] T098 [US8] Implement indexed authoritative dashboard aggregates and explicit admin/store origin summaries in `app/Services/OnlineStore/OnlineStoreDashboardService.php`
- [ ] T099 [US8] Implement filtered/timezone-aware report queries that reconcile to SalesOrders, listings, redemptions, reviews, stock reads, and ledger activity in `app/Services/OnlineStore/OnlineStoreReportService.php`
- [ ] T100 [US8] Implement settings/audit writes with `Online Store Settings Manage` and dashboard/report reads with `Online Store View` in `app/Http/Controllers/API/OnlineStore/StoreGovernanceController.php`
- [ ] T101 [US8] Map typed settings to the legacy `isClose/message/call/whatsApp` response without weakening protected writes in `app/Http/Controllers/API/Store/StoreSettingsController.php`
- [ ] T102 [US8] Register governance/dashboard/report endpoints in `routes/api.php`

**Checkpoint**: US8 independently provides authoritative governance, auditability, and reconciled measurement.

---

## Phase 10: User Story 7 - Moderate Reviews and Communicate (Priority: P3)

**Goal**: Moderate Product/customer reviews and deliver Store notifications through existing notification infrastructure.

**Independent Test**: Submit an owned review, derive verified purchase from completed order evidence, moderate it under exact permission, and send localized order/marketing notifications with safe destination metadata.

### Tests for User Story 7

- [ ] T103 [P] [US7] Add review rating/state, Product/customer/User ownership, cross-account IDOR, moderation, source-order deletion, and server-derived verified-purchase tests in `tests/Feature/OnlineStore/OnlineStoreReviewTest.php`
- [ ] T104 [P] [US7] Add notification recipient eligibility, locale fallback, template-control, FCM payload/deep-link, blocked-user, and sensitive-data exclusion tests in `tests/Feature/OnlineStore/OnlineStoreNotificationTest.php`
- [ ] T105 [P] [US7] Add frozen legacy comments empty/success envelope compatibility tests around review delegation in `tests/Feature/OnlineStore/LegacyCommentCompatibilityTest.php`

### Implementation for User Story 7

- [ ] T106 [P] [US7] Implement `OnlineStoreReview` with Product/customer/User relations, nullable SalesOrder, rating 1..5, states `pending|published|rejected`, moderation attribution, and indexed history in `app/Models/OnlineStore/OnlineStoreReview.php`
- [ ] T107 [US7] Implement review ownership, submission, moderation, and verified-purchase derivation from eligible completed/non-reversed SalesOrder items in `app/Services/OnlineStore/OnlineStoreReviewService.php`
- [ ] T108 [US7] Extend existing notification types/templates and deep-link metadata for Store order/marketing events in `app/Services/AdminNotificationService.php` and `app/Support/NotificationCatalog.php`
- [ ] T109 [US7] Implement Store notification orchestration through existing FCM/template controls and recipient eligibility in `app/Services/OnlineStore/OnlineStoreNotificationService.php`
- [ ] T110 [US7] Implement review Admin/customer endpoints and `Online Store Reviews Manage` permission/ownership checks in `app/Http/Controllers/API/OnlineStore/ReviewController.php` and `app/Http/Controllers/API/Store/StoreCommentsController.php`
- [ ] T111 [US7] Register review and notification endpoints while retaining legacy route names/envelopes in `routes/api.php` and `routes/api_store.php`

**Checkpoint**: US7 independently delivers review trust and Store communication without a parallel notification system.

---

## Phase 11: Flutter Admin Management Experience (Cross-Repository)

**Purpose**: Deliver the authorized manager workflows in the existing Flutter Admin repository `F:/flutter_projects/doctorbike` (`HazemAyyad/dr-bike-app-administration`) using its `data/domain/presentation`, GetX binding, and route conventions. Every task depends on its named backend capability and MUST treat backend APIs as authoritative rather than duplicating Product, stock, SalesOrder, debt, customer, supplier, or pricing state.

**Repository boundary**: Implement this phase on its own feature branch and focused commit(s) in `dr-bike-app-administration`; never stage or commit these Flutter files together with Laravel or `dr-bike-store` changes.

### Flutter Admin shared foundation

- [ ] T112 [P] Create Online Store Admin API endpoint constants, response/request models, datasource, repository contract/implementation, and controller binding after authenticated backend Admin routes exist in `F:/flutter_projects/doctorbike/lib/core/databases/api/end_points.dart`, `F:/flutter_projects/doctorbike/lib/features/admin/online_store/data/online_store_models.dart`, `F:/flutter_projects/doctorbike/lib/features/admin/online_store/data/online_store_datasource.dart`, `F:/flutter_projects/doctorbike/lib/features/admin/online_store/domain/online_store_repository.dart`, `F:/flutter_projects/doctorbike/lib/features/admin/online_store/data/online_store_repository_impl.dart`, and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/bindings/online_store_binding.dart`
- [ ] T113 [P] Add parsing and request-contract tests for listings/readiness/media, categories/content, discounts, accounts/credit, reviews/settings/audit, dashboard/reports, and explicit order origin after the corresponding backend contracts are stable in `F:/flutter_projects/doctorbike/test/online_store_models_test.dart` and `F:/flutter_projects/doctorbike/test/online_store_api_contract_test.dart`
- [ ] T114 Add the exact permission-name constants `Online Store View`, `Online Store Products Manage`, `Online Store Categories Manage`, `Online Store Content Manage`, `Online Store Promotions Manage`, `Online Store Reviews Manage`, and `Online Store Settings Manage`, plus permission-aware presentation helpers, after backend permission seeding is complete in `F:/flutter_projects/doctorbike/lib/core/services/initial_bindings.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/utils/online_store_permissions.dart`
- [ ] T115 [P] After backend permission seeding and protected Admin endpoints are available, add Flutter tests proving permission-aware entry/actions and proving Online Store forms expose no editable authoritative base-price or stock controls, while documenting that backend denial remains authoritative, in `F:/flutter_projects/doctorbike/test/online_store_permissions_test.dart` and `F:/flutter_projects/doctorbike/test/online_store_no_price_stock_edit_test.dart`
- [ ] T116 Add the permission-aware Online Store dashboard entry, GetX routes/pages, and binding after `Online Store View` backend access is available in `F:/flutter_projects/doctorbike/lib/features/admin/admin_dashbord/presentation/widgets/actions_buttons.dart`, `F:/flutter_projects/doctorbike/lib/routes/app_routes.dart`, and `F:/flutter_projects/doctorbike/lib/routes/app_pages.dart`

### Flutter Admin catalog and content management

- [ ] T117 [US8] Implement the Online Store dashboard shell, summary cards, loading/error/empty states, and navigation after backend dashboard aggregates are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_dashboard_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_dashboard_controller.dart`
- [ ] T118 [US1] Implement inventory Product selection plus listing list/create/edit screens after backend listing CRUD/readiness APIs are available, referencing Product IDs and remote state rather than copying Product/price/stock state, in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_listings_screen.dart`, `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_product_picker_screen.dart`, and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_listings_controller.dart`
- [ ] T119 [US1] Implement readiness issue display, draft/ready/publish/hide actions, concurrency/error feedback, and navigation to the authoritative inventory Product details route after backend lifecycle APIs are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_listing_editor_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/widgets/online_store_listing_readiness.dart`
- [ ] T120 [P] [US3] Add widget/controller tests for automatic default main media, readiness blockers, select-main/reorder/hide/show state, and non-mutation of source Product media after backend media contracts are stable in `F:/flutter_projects/doctorbike/test/online_store_media_presentation_test.dart`
- [ ] T121 [US3] Implement Store media presentation using existing Product media, automatic default-main display, main selection, reorder, hide/show, and exceptional Store-specific upload only when supported after backend media APIs are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_media_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_media_controller.dart`
- [ ] T122 [US2] Implement Store category hierarchy CRUD/reorder and listing assignment after backend category/membership APIs are available, without reusing inventory categories or physical `store_sections`, in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_categories_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_categories_controller.dart`
- [ ] T123 [US2] Implement typed manual/automatic home-section management with compatible target pickers and deterministic ordering after backend section APIs are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_home_sections_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_home_sections_controller.dart`
- [ ] T124 [US2] Implement scheduled banner CRUD/order, multilingual content, image, and validated action target editing after backend banner APIs are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_banners_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_banners_controller.dart`

### Flutter Admin commercial and operational management

- [ ] T125 [US4] Implement global/targeted promotion CRUD, activation, scheduling, priority, retail/wholesale applicability, and read-only pricing preview after backend PromotionService endpoints are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_promotions_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_promotions_controller.dart`
- [ ] T126 [US4] Implement coupon CRUD, eligibility/target/limit fields, activation, and redemption usage inspection after backend CouponService endpoints are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_coupons_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_coupons_controller.dart`
- [ ] T127 [P] [US5] Add parsing/widget tests for explicit `admin|store` SalesOrder origin labels and filters after backend origin fields/report filters are available in `F:/flutter_projects/doctorbike/test/sales_order_origin_display_test.dart`
- [ ] T128 [US5] Extend the existing SalesOrder model, datasource filters, toolbar/table/detail presentation to parse and display explicit origin and filter Admin/Store orders without changing existing order authority after backend origin support is available in `F:/flutter_projects/doctorbike/lib/features/admin/sales_orders/data/models/sales_order_model.dart`, `F:/flutter_projects/doctorbike/lib/features/admin/sales_orders/data/datasources/sales_orders_datasource.dart`, `F:/flutter_projects/doctorbike/lib/features/admin/sales_orders/presentation/controllers/sales_orders_controller.dart`, `F:/flutter_projects/doctorbike/lib/features/admin/sales_orders/presentation/widgets/sales_orders_toolbar.dart`, and `F:/flutter_projects/doctorbike/lib/features/admin/sales_orders/presentation/widgets/sales_orders_table.dart`
- [ ] T129 [US5] Implement linked Store user/customer/supplier account search, explicit-role linking, verification/conflict display, and suspension after backend account-link APIs are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_accounts_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_accounts_controller.dart`
- [ ] T130 [US6] Implement explicit credit eligibility, optional limit, expiry, currency, and ledger-derived current-debt/available-credit display after backend credit-policy APIs are available, with no locally maintained balance, in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_credit_policy_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_credit_controller.dart`
- [ ] T131 [US7] Implement pending/published/rejected review lists, verified-purchase indicator, moderation reason/actions, and exact permission visibility after backend review APIs are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_reviews_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_reviews_controller.dart`

### Flutter Admin governance and reporting

- [ ] T132 [US8] Implement typed Store settings with operating-state precedence and no base-price/stock controls after backend settings APIs are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_settings_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_settings_controller.dart`
- [ ] T133 [US8] Implement permission-protected Store audit browsing/filtering with redacted before/after details after backend audit APIs are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_audit_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_audit_controller.dart`
- [ ] T134 [US8] Implement Store dashboard/report filters, explicit origin summaries, listing/discount/review/stock/debt metrics, unavailable-state presentation, and drill-down navigation after backend reporting APIs are available in `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/views/online_store_reports_screen.dart` and `F:/flutter_projects/doctorbike/lib/features/admin/online_store/presentation/controllers/online_store_reports_controller.dart`
- [ ] T135 [P] Add Flutter Admin integration/widget coverage for dashboard navigation, listing lifecycle/readiness, categories/content, promotions/coupons, linked accounts/credit, reviews/settings/audit/reports, and permission-hidden actions after all corresponding backend capabilities are stable in `F:/flutter_projects/doctorbike/test/online_store_admin_workflows_test.dart`

**Checkpoint**: Authorized managers can use every approved workflow from Flutter Admin, with backend authority and exact permissions preserved.

---

## Phase 12: Compatibility, Security Gate, and Cross-Cutting Verification

**Purpose**: Prove safe legacy rollout, implement the narrowly required password-reset migration boundary, and run complete regression/reconciliation checks.

- [ ] T136 Add full frozen `routes/api_store.php` contract regression coverage for auth, users, settings, ads, notifications, comments, categories, items, cities, order create/history/cancel, errors, and empty results in `tests/Feature/OnlineStore/LegacyStoreContractTest.php`
- [ ] T137 Add password-reset security tests for generic forgot responses, hashed/expiring/rate-limited OTP, account-bound verification, opaque single-use proof, wrong/expired/reused/cross-account denial, no user enumeration, no userId-only reset, and no secret logs in `tests/Feature/OnlineStore/StorePasswordResetSecurityTest.php`
- [ ] T138 Implement secure Store forgot/verify/reset server flow gated by minimum supported Store client version, never returning OTP and never accepting userId alone, in `app/Http/Controllers/API/Store/StoreAuthController.php`
- [ ] T139 Implement hashed OTP lifecycle and opaque single-use account-bound reset proof issuance/consumption in `app/Services/OnlineStore/StorePasswordResetService.php`
- [ ] T140 In the separate `F:/flutter_projects/doctorbike_store` repository, create its own feature branch and focused commit implementing only the minimum Store-client migration: stop parsing returned OTP, call server OTP verification, carry the opaque reset proof, forbid reset-by-userId alone, and generate one stable UUID per checkout attempt in `F:/flutter_projects/doctorbike_store/lib/core/model/otp_model.dart`, `F:/flutter_projects/doctorbike_store/lib/repository/auth/auth_repository.dart`, `F:/flutter_projects/doctorbike_store/lib/controller/auth/forgetpassword.controller.dart`, and `F:/flutter_projects/doctorbike_store/lib/controller/shop/shop_controller.dart`; never combine Laravel, Flutter Admin, and Store-client files in one commit, preserve the backend/minimum-client-version rollout dependency, and do not introduce Store V2 or redesign work
- [ ] T141 Add regression tests that protected legacy user/order writes require bearer identity and ignore request-supplied ownership while public catalog reads retain approved compatibility in `tests/Feature/OnlineStore/LegacyStoreAuthorizationTest.php`
- [ ] T142 Add end-to-end stock/order/settlement/debt/accounting/cancellation/return/delivery/Shiply reconciliation coverage for customer and supplier Store orders in `tests/Feature/OnlineStore/OnlineStoreEndToEndTest.php`
- [ ] T143 Run targeted unit and feature suites from `specs/001-online-store-management/quickstart.md` against a verified disposable schema and record results/boundaries in `specs/001-online-store-management/validation-report.md`
- [ ] T144 Run legacy contract, authorization/IDOR, native idempotency, legacy dedupe, coupon concurrency, credit, completed reporting reconciliation including the US7 pending-review aggregate, and password-reset security suites and append exact results to `specs/001-online-store-management/validation-report.md`
- [ ] T145 Re-run the constitution/source-of-truth audit and document proof that Product, SalesOrder, stock, debt, base price, `store_sections`, permissions, and financial transactions retain their approved authorities in `specs/001-online-store-management/validation-report.md`
- [ ] T146 Verify the staged rollout/rollback switches never restore insecure direct order writes or OTP leakage and document recovery steps without dropping financial/audit history in `specs/001-online-store-management/validation-report.md`

**Checkpoint**: Compatibility, security gate, financial reconciliation, and constitution evidence are complete.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 — Setup**: Starts immediately.
- **Phase 2 — Foundation**: Depends on Phase 1 and blocks every user story.
- **US1 (Phase 3)**: Depends on Phase 2; suggested MVP.
- **US2 (Phase 4)**: Depends on Phase 2 and uses listing eligibility from US1 for final storefront output.
- **US3 (Phase 5)**: Depends on Phase 2; integrates readiness with US1 but media initialization work can proceed in parallel after foundation.
- **US5 (Phase 6)**: Depends on Phase 2 and consumes published listing/price/availability behavior from US1/US3 for complete checkout.
- **US4 (Phase 7)**: Depends on Phase 2 and `StorePriceResolver` from US3; checkout integration depends on US5.
- **US6 (Phase 8)**: Depends on US5 identity/checkout foundation.
- **US8 (Phase 9)**: Settings/permissions/audit can start after Phase 2; complete reports depend on US1–US6 data sources.
- **US7 (Phase 10)**: Depends on US5 identity/order evidence for verified purchase; notification work may start after Phase 2.
- **Phase 11 — Flutter Admin**: Each task depends on the backend capability named in its description; shared data/routing work starts after stable Admin contracts, while each management screen follows its corresponding backend story. Work occurs on a separate `dr-bike-app-administration` branch/commit.
- **Phase 12 — Compatibility/security verification**: Depends on all selected stories and required Flutter client work; password-reset tasks may start after Phase 2 but rollout proof completes last.

### User Story Dependency Graph

```text
Setup -> Foundation -> US1 -----> US2
                    |   \
                    |    +-----> US3 -----> US4
                    |               \
                    +--------------> US5 -----> US6
                                      | \
                                      |  +-----> US7
                                      +--------> US8 reports

All selected stories -> Compatibility/Security/Constitution verification
```

### Within Each User Story

1. Write the listed tests and confirm they fail for the intended missing behavior.
2. Add models/casts/relations before services.
3. Add transactional services before controllers/routes.
4. Integrate only through existing authoritative Product, stock, SalesOrder, ledger, accounting, media, permission, delivery, and notification services.
5. Pass the independent test and existing affected regression suite before moving past the checkpoint.

### Parallel Opportunities

- T002, T004, and T005 can proceed in parallel after T001 defines paths.
- T009–T012 and T017–T018 can proceed in parallel after T003, with T013–T016 following schema agreement.
- US1 listing lifecycle, US3 media/authority, and US5 account-link/origin foundations can proceed in parallel after Phase 2, then converge at checkout.
- US2 category, section, and banner model/test tasks marked `[P]` can run concurrently.
- US4 promotion, coupon, and redemption test/model tasks marked `[P]` can run concurrently.
- US8 settings, permissions, audit, reporting, and performance tests marked `[P]` can run concurrently before service integration.
- US7 review and notification tests/models marked `[P]` can run concurrently.

## Parallel Execution Examples

### US1 + US3 early catalog work

```text
Task T020: Listing model/constraint tests
Task T021: Listing API/lifecycle tests
Task T042: Media initialization tests
Task T044: Price/stock authority tests
```

### US5 identity and order safety

```text
Task T052: Account-link tests
Task T053: Explicit-origin tests
Task T054: Native idempotency concurrency tests without coupon/credit assertions
Task T055: Bounded legacy checkout tests
Task T056: Order ownership/cancel tests
```

### US8 governance and reports

```text
Task T089: Settings precedence tests
Task T090: Permission matrix tests
Task T091: Audit/redaction tests
Task T092: Reporting reconciliation tests
Task T093: Performance acceptance harness
```

---

## Implementation Strategy

### MVP First

1. Complete Phase 1 safety baseline.
2. Complete Phase 2 additive schema/foundation.
3. Complete US1 only.
4. Stop and validate one-Product/one-listing lifecycle, completeness, publication, permissions, and no Product mutation.

### Incremental Delivery

1. Add US3 accurate media/price/stock so published listings are trustworthy.
2. Add US2 discovery/content for customer navigation.
3. Add US5 identity/orders with native idempotency and bounded legacy mitigation.
4. Add US4 discounts and US6 approved credit after the checkout boundary is authoritative.
5. Add US8 governance/reports and US7 reviews/notifications.
6. Complete Phase 11 Flutter Admin delivery on its separate repository branch/commit.
7. Complete Phase 12 security-gated compatibility and full reconciliation before broad rollout.

### Required Stop Conditions

- Stop if a migration would overwrite, reinterpret, or duplicate authoritative Product, stock, SalesOrder, debt, accounting, media, customer, seller, or `store_sections` data.
- Stop if database-backed tests cannot prove they target a disposable schema.
- Stop rollout of secure password reset until the minimum compatible Store client exists; do not fall back to OTP disclosure or userId-only reset.
- Do not claim strict late-retry idempotency for unchanged legacy checkout; full protection starts with its client-generated per-attempt UUID.

## Notes

- `[P]` means file-level work can proceed concurrently, not that shared database/service integration can merge without coordination.
- Every financial/stock/coupon/idempotency mutation must remain transaction-safe and server-authoritative.
- Existing Admin order behavior remains compatible and is not forced to send `client_request_id`.
- Store-specific media is optional and exceptional when valid Product media is insufficient.
- Commit only focused task paths; keep unrelated worktree changes untouched.
