# Phase 12 Final Validation Report (T142-T146)

**Date**: 2026-10-04

**Branch**: `feat/online-store-management`

**Backend baseline**: `50994719a445fbb00f8f941396be8467012608b7`

**Approved Store client**: `72c8212632124ed8651c465a5d01aaf25429d2c6`
**Scope**: T142, T143, T144, T145, and T146 only

## Evidence status

This report deliberately separates executed evidence from discovery and static review. No approved
disposable MySQL/MariaDB schema was configured in this session. `ONLINE_STORE_DISPOSABLE_DB` and
`ONLINE_STORE_DISPOSABLE_DB_NAME` were absent, so the database guard stopped every attempted
Online Store Feature test before `migrate:fresh` or fixture persistence. No local, staging-like, or
production-like database was migrated or modified.

### Task completion status

| Task | Status |
|---|---|
| T142 | Definitions complete |
| T143 | Pending execution against a verified disposable database |
| T144 | Pending runtime regression, security, and concurrency execution |
| T145 | Constitution and source-of-truth audit complete |
| T146 | Rollout and rollback documentation complete |

**Phase 12 runtime acceptance is not yet closed.** T143 and T144 remain open until the required
Feature suites execute successfully against an approved disposable database. Existing discovery,
static review, and safe Unit evidence below remains valid but does not substitute for that execution.

### Executed and passed

| Check | Result |
|---|---|
| Safe Online Store Unit tests (excluding the DB-backed media service test) | 23 tests, 146 assertions, passed; one PHPUnit deprecation notice |
| PHP lint for Online Store application and test scope | 96 PHP files, zero failures |
| Pint check for `OnlineStoreEndToEndTest.php` | Passed |
| T142 test discovery | 5 cases discovered |
| Complete Online Store Feature discovery | 134 cases discovered |
| Admin Online Store route discovery | 61 routes registered |
| Legacy Store route discovery | 29 routes registered with their existing methods and paths |

The final commit gate also runs PHP lint on every changed PHP file, Pint on the task PHP file,
targeted safe Unit tests, route discovery, and `git diff --check`; its final result is recorded in the
commit handoff.

### Discovered but not executed

| Suite | Cases | Reason |
|---|---:|---|
| `tests/Feature/OnlineStore` | 134 | No approved disposable database opt-in/name was available |
| `OnlineStoreEndToEndTest` | 5 | Same guard; direct attempt returned five guard errors and zero assertions |
| `MediaPresentationServiceTest` | 1 | It is located under Unit but intentionally invokes the disposable DB guard |

The exact guard result was:

```text
RuntimeException: Set ONLINE_STORE_DISPOSABLE_DB=true to opt in to disposable database tests.
```

The guard is defined in `tests/Support/RequiresDisposableDatabase.php` and is invoked centrally for
`Tests\Feature\OnlineStore\*` before each test setup. This is an environment blocker, not a test
failure and not runtime E2E proof. A later authorized run must use a schema whose configured and
expected names match and visibly contain `test`, `testing`, or `disposable`.

## T142 - End-to-end order and reconciliation definitions

`tests/Feature/OnlineStore/OnlineStoreEndToEndTest.php` adds five DB-backed cases through existing
application services and fixtures:

1. Customer cash checkout proves `sales_orders.origin=store`, authenticated `origin_user_id`, stable
   `client_request_id`, correct customer/partner linkage, one item/reservation/status/notification,
   no debt or settlement, same-UUID replay reuse, and cancellation release without stock drift.
2. Customer mixed checkout proves 2,000 total, 500 settlement, 1,500 customer ledger debt, one
   accounting projection source key, and no replay duplication.
3. Seller mixed checkout proves the same 2,000/500/1,500 reconciliation on the seller dimension,
   with no accidental customer linkage and no replay duplication.
4. Legacy checkout proves the existing authenticated-actor/canonical-payload two-minute dedupe,
   server-authoritative price, ignored client identity/total/timestamp, and a server-generated
   `legacy:` request identifier. It does not claim indefinite native idempotency.
5. The supported return path proves dispatched stock restoration, `with_delivery -> returned`
   lifecycle logging, Shiply cancellation delegation, and idempotent Shiply handover tracking.

Coupon reservation/consumption/release and race coverage remains in
`CouponRedemptionConcurrencyTest`; credit rejection/limit locking/rollback remains in
`StoreCreditConcurrencyTest`; full mixed-payment rollback and accounting-failure atomicity remains in
`StoreCreditCheckoutTest`. T142 composes the authoritative services rather than reproducing their
business logic in the test.

### Financial reconciliation matrix

| Mode | Expected accepted effects | Exactly-once evidence |
|---|---|---|
| Cash at order creation | Order and reservation; no initial settlement/debt | E2E cash replay counts one order/item/status/notification and zero debt/settlement |
| Credit | Eligible authoritative link/policy; unpaid total in debt ledger | Existing credit checkout and concurrency cases discover one order/debt source and reject ineligible/over-limit flows atomically |
| Mixed | Paid portion settlement plus unpaid ledger exposure | E2E customer/seller cases assert 2,000 total, 500 settlement, 1,500 debt, one accounting source key |
| Coupon | One reserved/applied redemption per accepted order | Coupon concurrency suite covers retry, last-use race, ordering before effects, cancellation release, and rollback |

## T143 - Targeted validation inventory

The 134 discovered Feature cases cover the requested areas as follows:

| Area | Primary suites |
|---|---|
| Listings, media, price, and stock authority | `ListingManagementTest`, `MediaPresentationTest`, `StoreCatalogAuthorityTest` |
| Categories, home content, and banners | `OnlineStoreCategoryTest`, `HomeSectionTest`, `BannerTest`, `ContentMediaUploadTest` |
| Promotions and coupons | `PromotionTest`, `CouponTest`, `CouponRedemptionConcurrencyTest` |
| Checkout and idempotency | `CheckoutIdempotencyTest`, `LegacyCheckoutCompatibilityTest`, `OnlineStoreEndToEndTest` |
| Account links and credit governance | `AccountLinkTest`, `StoreCreditCheckoutTest`, `StoreCreditConcurrencyTest` |
| Reviews and notifications | `OnlineStoreReviewTest`, `OnlineStoreNotificationTest`, `LegacyCommentCompatibilityTest` |
| Legacy compatibility and Store updates | `LegacyStoreContractTest`, `LegacyStoreAuthorizationTest`, `StoreUpdateCheckTest` |
| Authorization and IDOR | `OnlineStorePermissionTest`, `StoreOrderOwnershipTest`, `LegacyStoreAuthorizationTest` |
| Secure password reset | `StorePasswordResetSecurityTest`, `StorePasswordResetRateLimitTest` |
| Accounting, debt, settlement, cancellation, returns, Shiply | `StoreCreditCheckoutTest`, `StoreOrderOwnershipTest`, `OnlineStoreEndToEndTest` |
| Reporting and performance | `OnlineStoreReportTest`, `OnlineStorePerformanceTest` |
| Schema and migration safety | `OnlineStoreSchemaMigrationTest`, `OnlineStoreDumpDerivedSchemaMigrationTest` |

Runtime execution of the Feature matrix remains deferred until the disposable schema prerequisite is
provided. Discovery proves definitions load; it does not prove database behavior.

## T144 - Security, concurrency, and compatibility evidence

### Security

- Store protected user/order writes resolve bearer identity; public catalog reads remain public where
  approved. The registered routes and `LegacyStoreAuthorizationTest` preserve this boundary.
- Exact Admin authorization remains server-side through seven named permissions and direct-resource
  policy checks. No UI permission is treated as authority.
- Cross-account order/link/review access uses inaccessible/not-found behavior to avoid IDOR leakage.
- Password reset definitions cover generic forgot responses, hashed OTP, expiry/attempt/rate limits,
  account-bound verification, opaque single-use proof, cross-account/reuse denial, no userId-only
  reset, old-build 426 gating, and secret-log exclusion.
- Audit payload tests cover password/token/OTP and unnecessary personal-data redaction.
- Store update tests keep Admin defaults independent and cover Store Android/iOS minimum-build gates.

### Concurrency and exactly-once boundaries

- Native checkout uniqueness is scoped to `(origin, origin_user_id, client_request_id)` and lookup is
  defined before side effects.
- Same UUID replay and cross-actor same-ID isolation are covered by idempotency and E2E definitions.
- Legacy checkout retains bounded canonical dedupe and intentionally permits a new order after expiry.
- Coupon capacity uses locks plus unique order attribution; competing last-use and rollback cases are
  defined.
- Stock reservation, credit exposure, settlement idempotency keys, debt source attribution, and
  accounting `source_key` uniqueness are asserted by the checkout/credit suites.
- Password-reset proof consumption is defined as single-use and account-bound.
- Shiply handover tracking uses deduplicated event identity; cancellation/return delegates to existing
  fulfillment and Shiply services.

### Compatibility

- The legacy compatibility layer remains isolated in `routes/api_store.php`.
- Route discovery found 29 legacy Store paths; no route method/path was changed in this slice.
- Frozen payload tests retain legacy envelopes and fields while testing additive `listingId` and
  `accountRoles` mappings.
- `listingId` maps `online_store_listings.id` by `product_id`; legacy `id` remains Product ID.
- `accountRoles` derives from active verified account links; `typeUser` remains a legacy field and is
  not checkout authority.
- No Flutter Admin contract or Store client source was modified in this slice.

## T145 - Constitution and source-of-truth audit

| Requirement | Evidence | Result |
|---|---|---|
| Product owns retail/wholesale price and stock identity | Listings reference `product_id`; checkout pricing/availability read Product/variant sources; listing requests expose no price/stock writes | PASS (static + Unit; Feature deferred) |
| Listing is merchandising/presentation only | Listing model/migration contains lifecycle, translations, flags, readiness, and Product FK, not authoritative price/stock balances | PASS |
| No `online_store_orders` table | Repository schema/model search and domain-boundary Unit test find no such table | PASS |
| Store orders use `sales_orders` | Checkout delegates to `SalesOrderService` and persists Store origin fields on `sales_orders` | PASS (static; Feature deferred) |
| Online categories remain separate | `online_store_categories` is separate from inventory categories and physical `store_sections` | PASS |
| Promotions/coupons only discount authoritative prices | Store pricing resolves base Product/variant price first and applies bounded discounts without listing price override | PASS (static + Unit; Feature deferred) |
| Admin Store routes cannot mutate Product stock/base price | Request/controller contract contains no authoritative stock/base-price mutation endpoint | PASS |
| Credit remains partner/ledger/accounting-owned | Policies store eligibility/limit only; exposure uses `DebtLedgerService`; projections use `AccountingProjectionService` | PASS (static; Feature deferred) |
| Legacy compatibility stays in `api_store.php` | Legacy Store paths remain registered from the compatibility route file | PASS |
| Unified identity uses account links | `StoreIdentityService` resolves active verified `online_store_account_links` for customer/seller roles | PASS |
| Exact seven permissions retained | Seeder and route middleware contain only the seven approved Online Store permissions | PASS |
| Auditability and rollback retained | Store governance writes have audit coverage; rollback retains transactional/audit history | PASS (static; Feature deferred) |

No constitution deviation was found. Because database Feature execution is deferred, this audit is
not a runtime acceptance sign-off.

## T146 - Rollout, rollback, and production verification

### Deployment order

1. Deploy the Laravel backend and additive migrations/configuration first.
2. Configure Store Android/iOS app-update settings without globally forcing old clients prematurely.
3. Release Store `2.2.1+10` or newer with secure reset proof and stable checkout UUID support.
4. Verify secure forgot/OTP verification/reset on build 10.
5. Verify customer, seller, and dual-role checkout plus retry behavior.
6. Only then raise/enforce minimum-build settings as explicitly approved per platform.

### Non-destructive rollback

- If Store `2.2.1+10` is rolled back, lower/disable the build gate only where explicitly intended;
  do not restore OTP disclosure or userId-only reset. Keep secure reset disabled for incompatible
  clients and direct users to upgrade/support.
- If the backend is rolled back, disable feature exposure/read adapters with approved switches while
  retaining additive tables/columns and all accepted `sales_orders`, settlements, debt transactions,
  accounting journals, coupon redemptions, account links, and audit events.
- If password-reset migration adoption is incomplete, do not enable the insecure legacy flow. Keep
  the minimum compatible build policy staged and monitor upgrade adoption.
- If native checkout has a client issue, disable only new checkout exposure as operationally
  configured; never route accepted writes back to direct legacy inserts. Preserve native request IDs
  and bounded legacy dedupe records for investigation.
- Never drop or rewrite transactional history as a rollback technique.

### Production verification checklist

- [ ] `/api/app/update-check` for Store Android and iOS
- [ ] Old build forgot-password receives 426 when the secure gate is enforced
- [ ] Build 10 forgot, server OTP verify, and proof-bound reset
- [ ] Login and legacy user payload, including `accountRoles`
- [ ] Catalog list/search/detail, including authoritative `listingId`
- [ ] Customer checkout and order history
- [ ] Seller checkout and order history
- [ ] Dual-role selection with no implicit role inference
- [ ] Coupon checkout and one redemption
- [ ] Lost-response retry returns the same native order
- [ ] Legacy near-retry remains bounded to the compatibility window
- [ ] Cancellation releases reservation/coupon and reconciles finance
- [ ] Stock reservation, dispatch, return/restoration
- [ ] Debt, settlement, and accounting reconciliation
- [ ] Order/marketing notifications without sensitive payloads
- [ ] Shiply handover, tracking, delivered/canceled/returned behavior where configured

These are production smoke checks and are not marked complete by local discovery or static review.

### Migration and commit history proof

- This final T142-T146 slice adds one Feature test file, this report, and task completion markers; it
  adds no migration and performs no schema/data mutation.
- The approved history remains additive: Store roles compatibility `5099471`, listing ID compatibility
  `35a2505`, legacy security regression `654c555`, secure compatibility `f8f86ba`, and earlier Online
  Store domain commits remain separate and reviewable.
- Repository search finds no `online_store_orders` schema. Online Store order history remains in
  `sales_orders`; rollback guidance retains all transactional and audit evidence.

## Blockers and deviations

- **Blocker**: no approved disposable database configuration was supplied, so 134 Feature cases and
  one DB-backed Unit case were not executed.
- **Deviation**: none from the requested safety boundary. The guard was not bypassed, assertions were
  not weakened, Flutter repositories were untouched, and Store V2 was not started.
- **Release conclusion**: T142 definitions, T145 audit, and T146 rollout/rollback documentation are
  complete. T143 and T144 remain pending an approved disposable-database run. Phase 12 runtime
  acceptance is **not yet closed**, and the Store product redesign is not declared complete.
