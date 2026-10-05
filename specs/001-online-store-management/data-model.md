# Data Model: Online Store Management

## Design rules

- `products`, variant stock, `sales_orders`, `users`, `customers`, `sellers`, `debt_transactions`,
  accounting journals, deliveries, and notifications remain authoritative.
- All new money values use `decimal(14,2)` and a bounded currency code; timestamps use the existing
  application/business timezone rules at the boundary and UTC-compatible persisted timestamps.
- Translation fields use JSON objects keyed by enabled locale where a fixed legacy three-column shape
  is not required. Empty translation values fall back to Product/source content.
- Polymorphic target columns use an application allow-list and indexed `(target_type, target_id)`;
  arbitrary class names are never accepted from clients.
- Actor FKs use `nullOnDelete` to preserve history. Authoritative entity FKs generally use
  `restrictOnDelete`; dependent presentation rows cascade with their Store parent.

## Existing authoritative tables: verified reuse

| Table | Existing responsibility | Store usage |
|-------|-------------------------|-------------|
| `products` | Identity, base retail/wholesale price, aggregate stock, inventory category/location | Referenced by one listing; never copied or repriced |
| `sizes`, `size_colors` | Variant identity, variant base prices and stock | Price/availability resolution and order item snapshot |
| `normal_image_products`, `image3d_products`, `view_image_products` | Product media | Referenced by presentation metadata; never deleted by Store hiding |
| `product_stock_movements` | Stock movement audit | Remains owned by stock/order services |
| `users` | Authentication and Store/Admin identity | Linked to customer/seller; origin actor and audit actor |
| `customers`, `sellers` | Business-party identity | Account ownership and credit party |
| `sales_orders`, `sales_order_items` | Single order source and price snapshots | Store orders use these same tables |
| `sales_order_settlements` | Idempotent cash/settlement history | Partial payments and delivery settlement |
| `debt_transactions` | Authoritative debt ledger | Current debt/available credit and unpaid order portion |
| accounting journal tables | Authoritative financial projection | Sales revenue, receivable, cash, inventory/COGS entries |
| delivery/Shiply/status tables | Fulfillment and lifecycle history | Unchanged existing flow |
| permission tables | Staff authorization | Seven Store permissions are seeded here |

## New entities

### 1. `online_store_listings`

One customer-facing presentation per Product.

| Field | Type/rule | Purpose and authority |
|-------|-----------|-----------------------|
| `id` | bigint PK | Store metadata identity |
| `product_id` | FK products, unique, restrict delete | Authoritative product; unique enforces one listing across every state |
| `status` | string: draft/ready/published/hidden | Listing lifecycle only |
| `name_translations` | JSON nullable | Optional Store display overrides; falls back to Product names |
| `description_translations` | JSON nullable | Optional Store display overrides; falls back to Product descriptions |
| `badge_translations` | JSON nullable | Optional merchandising badge |
| `is_featured`, `is_new`, `show_on_home`, `show_as_offer` | boolean default false | Independent merchandising flags |
| `sort_order` | unsigned int default 0 | Stable listing order |
| `readiness_state` | string default incomplete | Cached operational state, never product authority |
| `readiness_issues` | JSON nullable | Deterministic missing-data codes for Admin display |
| `published_at`, `hidden_at` | timestamp nullable | Lifecycle evidence |
| `created_by`, `updated_by` | nullable FK users | Actor attribution |
| timestamps | standard | History anchors |

Indexes: unique `product_id`; `(status, sort_order)`; `(readiness_state, status)`; flags combined with
`status` where query evidence shows benefit. No price or stock column is permitted.

State transitions:

```text
draft -> ready -> published -> hidden
  ^        |          |          |
  +--------+----------+----------+
```

Any state may fall back to draft/incomplete when required data is lost. Only complete/eligible rows
may enter published. Hidden is the same row, not history/versioning.

### 2. `online_store_categories`

| Field | Type/rule | Purpose |
|-------|-----------|---------|
| `id` | bigint PK | Category identity |
| `parent_id` | nullable self FK, restrict/null per deletion policy | Hierarchy; cycles prohibited in service validation |
| `name_translations`, `description_translations` | JSON | Multilingual merchandising content |
| `image_path` | string nullable | Category presentation image |
| `is_active`, `show_on_home` | boolean | Visibility controls |
| `sort_order` | unsigned int | Sibling order |
| `created_by`, `updated_by` | nullable FK users | Attribution |
| timestamps | standard | Change timing |

Indexes: `(parent_id, is_active, sort_order)` and `(show_on_home, is_active, sort_order)`. This table
has no relation to inventory categories or `store_sections` beyond optional read-only Admin references.

### 3. `online_store_category_listing`

| Field | Type/rule | Purpose |
|-------|-----------|---------|
| `online_store_category_id` | FK, cascade | Merchandising category |
| `online_store_listing_id` | FK, cascade | Listing membership |
| `sort_order` | unsigned int default 0 | Per-category ordering |
| timestamps | standard | Assignment history anchor |

Composite unique on category/listing; indexes on listing and `(category, sort_order)`.

### 4. `online_store_media_presentations`

| Field | Type/rule | Purpose |
|-------|-----------|---------|
| `id` | bigint PK | Presentation identity |
| `online_store_listing_id` | FK, cascade | Owning listing |
| `source_type` | allow-list: normal_image/image3d/view_image/variant/store_specific | Source kind |
| `source_id` | bigint nullable | Existing media row/variant ID; required except Store-specific source |
| `store_media_path` | string nullable | Exceptional Store-only asset; mutually exclusive with source_id |
| `media_metadata` | JSON nullable | Video/3D/360 presentation metadata supported by source capability |
| `is_main`, `is_visible` | boolean | Store-only presentation flags |
| `sort_order` | unsigned int | Display sequence |
| `created_by`, `updated_by` | nullable FK users | Attribution |
| timestamps | standard | Change timing |

Unique `(listing_id, source_type, source_id)` for referenced media; enforce at most one main visible
item per listing transactionally (and with a generated-key/compatible constraint if supported).
Service validation proves referenced media belongs to the listing Product. On first listing creation,
create rows for all valid Product media in this deterministic order: `view_image` rows ordered by
source row ID, then `normal_image` rows by source row ID, then `image3d` rows by source row ID. Assign
sequential Store `sort_order` values and mark the first row as `is_main=true`. This is application
priority, not an inherited inventory sort/main value; the source schema has neither. Later Store
reordering, hiding, and main selection mutate only these presentation rows. A draft may have no media,
but publication requires one usable visible main row. Store-specific media is optional and exceptional.

### 5. `online_store_promotions`

| Field | Type/rule | Purpose |
|-------|-----------|---------|
| `id` | bigint PK | Promotion identity |
| `name`, `description_translations` | string/JSON | Admin and Store presentation |
| `discount_type` | percentage/fixed | Discount semantics |
| `discount_value` | decimal(14,2), positive | Rule value, not base price |
| `applies_to` | retail/wholesale/both | Customer context |
| `scope` | global/targeted | Explicit target behavior; never inferred from row absence |
| `starts_at`, `ends_at` | timestamp nullable | Inclusive validity window; end > start |
| `is_active` | boolean | Operational switch |
| `priority` | unsigned int default 0 | Deterministic conflict selection |
| `created_by`, `updated_by` | nullable FK users | Attribution |
| timestamps | standard | Audit anchors |

Indexes: `(is_active, starts_at, ends_at)`, `(scope,is_active)`, `(applies_to, priority)`. Percentage
max 100; fixed discount cannot make an eligible amount negative. V1 chooses one winning promotion by
documented priority. Activation validates: global has zero target rows; targeted has at least one valid
target row.

### 6. `online_store_promotion_targets`

Fields: `promotion_id` FK cascade, allow-listed `target_type` (`listing` or `category`), `target_id`,
timestamps. Unique `(promotion_id,target_type,target_id)`; index `(target_type,target_id)`. Rows are
for `scope=targeted` only. `scope=global` must have none; a targeted promotion with zero valid rows is
invalid and cannot activate. No missing-row inference or magic target ID is permitted.

### 7. `online_store_coupons`

Fields: `id`; normalized `code` with case-insensitive unique index; `discount_type`; positive
`discount_value`; nullable `starts_at/ends_at`; `minimum_order decimal(14,2)`; nullable unsigned
`total_usage_limit` and `per_user_usage_limit`; `eligible_account_type` (`customer`, `seller`, `both`);
`applies_to` (`retail`, `wholesale`, `both`); `is_active`; `scope` (`global`, `targeted`);
`created_by/updated_by`; timestamps.

This is discount configuration only. It contains no price authority or mutable authoritative balance.
Indexes cover active dates and normalized code.

### 8. `online_store_coupon_targets`

Fields and constraints parallel promotion targets, with `coupon_id`. Target types are listing/category.

### 9. `online_store_coupon_redemptions`

| Field | Type/rule | Purpose |
|-------|-----------|---------|
| `id` | bigint PK | Redemption evidence |
| `coupon_id` | FK restrict | Coupon definition |
| `sales_order_id` | FK restrict, unique | Accepted authoritative order; one coupon redemption per order in V1 |
| `user_id` | FK restrict | Authenticated redeemer |
| `customer_id`, `seller_id` | nullable FKs | Resolved business party; exactly one for redemption counting |
| `discount_amount` | decimal(14,2) | Server-calculated snapshot |
| `status` | reserved/applied/released | Concurrency/cancellation lifecycle |
| `applied_at`, `released_at` | timestamps nullable | Evidence |
| timestamps | standard | Audit anchors |

Indexes: unique order; `(coupon_id,status)` for total use; `(coupon_id,user_id,status)` for per-user
limits. Reservation and order creation occur in one transaction with locked limit evaluation.

### 10. `online_store_home_sections`

Fields: `id`, unique stable `key`, `section_type` (hero/categories/best_sellers/recent/maintenance/
offers/custom), `title_translations` JSON, `selection_mode` manual/automatic/dedicated_banners,
`selection_config` JSON validated per type, `is_visible`, `sort_order`, actor FKs, timestamps. Index
`(is_visible,sort_order)`.

Composition rules:

- `hero`: `dedicated_banners`; reads active scheduled banners ordered by banner `sort_order,id`; no
  generic item rows.
- `categories`: manual typed category items or automatic validated category `selection_config`.
- `best_sellers`, `recent`, `offers`, `custom`: manual listing items or validated automatic listing
  `selection_config`; `custom` automatic mode is allowed only for a named server-supported selector.
- `maintenance`: automatic validated configuration referencing the existing maintenance destination
  or content key; no listing/category item rows.

For automatic sections, configuration records an allow-listed selector plus bounded limit/filter
values; it never accepts SQL, class names, or raw query fragments. Visible section ordering is
`sort_order,id`; automatic result tie-breaks and eligibility are server-defined per selector.

### 11. `online_store_home_section_items`

Fields: `id`, `home_section_id` FK cascade, allow-listed `target_type` (`listing` or `category`),
`target_id`, `sort_order`, timestamps. Unique `(home_section_id,target_type,target_id)`; index
`(home_section_id,sort_order,id)` and `(target_type,target_id)`. Used only for manual selection.
Product-like sections accept only `listing`; Categories accepts only `category`; hero, maintenance,
and automatic sections reject item rows. Targets reference the corresponding online Store records
without copying data. The service validates compatibility and existence and orders by `sort_order,id`;
hidden/inactive/unpublished targets are omitted from Store output deterministically.

### 12. `online_store_banners`

Fields: `id`, `image_path`, `title_translations`, `content_translations`, `is_active`, nullable
`starts_at/ends_at`, `sort_order`, `action_type` (listing/category/promotion/url/none), nullable
`action_target_id`, nullable `action_url`, actor FKs, timestamps. Index active/date/order. Validation
requires exactly the target appropriate to action type and rejects unsafe URL schemes.

### 13. `online_store_account_links`

| Field | Type/rule | Purpose |
|-------|-----------|---------|
| `id` | bigint PK | Link identity |
| `user_id` | FK users, restrict | Authentication identity |
| `customer_id` | nullable FK customers, restrict | Existing customer role |
| `seller_id` | nullable FK sellers, restrict | Existing supplier role |
| `role` | customer/seller | Explicit role for this row |
| `account_source` | store_app/admin_app/import | Provenance, never inferred from serial/UI |
| `status` | pending/active/suspended | Link lifecycle |
| `linked_by`, `verified_by` | nullable FK users | Attribution |
| `verified_at` | timestamp nullable | Approval evidence |
| timestamps | standard | Change timing |

Exactly one party FK matches `role`. Unique `(user_id,role)`, unique active customer link, and unique
active seller link prevent ambiguous ownership. If MySQL cannot express status-scoped uniqueness,
retain historical changes in audit events and update the same link row instead of inserting versions.

### 14. `online_store_credit_policies`

Fields: `id`, `account_link_id` unique FK cascade/restrict, `is_eligible` default false, nullable
`credit_limit decimal(14,2)`, `currency`, `approved_by` nullable FK users, `approved_at`, `expires_at`,
`notes`, timestamps. No current balance/available-credit field is allowed. Eligibility requires an
active verified link; limit is non-negative; expiry disables new credit without changing ledger debt.

### 15. `online_store_reviews`

Fields: `id`, `product_id` FK restrict, `customer_id` FK restrict, `user_id` FK restrict, optional
`sales_order_id` FK nullOnDelete, `rating` unsigned tinyint 1..5, `comment`, `status`
(pending/published/rejected), derived `is_verified_purchase`, moderator FK/timestamp/reason,
timestamps. Index `(product_id,status,created_at)` and `(customer_id,created_at)`. Verification is
recomputed from eligible completed order evidence and never accepted from clients.

### 16. `online_store_settings`

Singleton row (`id=1`) with: `store_enabled`, `maintenance_mode`, `checkout_enabled`, `cod_enabled`,
`guest_browsing_enabled`, `minimum_order decimal(14,2)`, `support_phone`, `whatsapp`,
`enabled_languages` JSON, policy translation JSON fields for cancellation/return/warranty/terms,
`out_of_stock_behavior` fixed to visible_non_purchasable in V1, `low_stock_threshold`, actor FK, and
timestamps. Check/service invariants enforce enabled language list, non-negative amounts, and V1
out-of-stock policy. The legacy settings adapter maps these fields to its existing response.

### 17. `online_store_audit_events`

Fields: `id`, `actor_user_id` nullable FK, `action`, `entity_type` allow-list, `entity_id`, `before_values`
JSON nullable, `after_values` JSON nullable, `request_id`/correlation key nullable, `ip_address`
nullable, `occurred_at`, timestamps. Index `(entity_type,entity_id,occurred_at)`, `(actor_user_id,
occurred_at)`, and action/time. Redact secrets, tokens, password data, and unnecessary personal data.

### 18. `online_store_legacy_checkout_attempts`

Compatibility-only expiring dedupe registry; it is not an order domain. Fields: `id`, `origin_user_id`
FK users, `request_fingerprint` fixed hash, nullable `sales_order_id` FK restrict, `expires_at`, and
timestamps. Unique `(origin_user_id,request_fingerprint)` and index `expires_at`. The fingerprint uses
the exact canonical inputs in `contracts/checkout-integration.md`. Under an actor/fingerprint lock, a
live accepted row returns its SalesOrder; an expired row is deleted/replaced before a new order begins.
The accepted SalesOrder receives a separate server-generated namespaced UUID in `client_request_id`,
so an intentional identical post-window purchase does not violate the SalesOrder unique key. Cleanup
removes only expired compatibility rows and never orders. This is bounded mitigation, not an authority
or proof of full retry idempotency.

## Existing `sales_orders` additions

| Column | Type/index | Why needed | Compatibility |
|--------|------------|------------|---------------|
| `origin` | nullable string then non-null/default `admin`; index `(origin,created_at)` | Reliable Store/Admin distinction and reports | Backfill every existing production row to owner-confirmed `admin`; new Admin=`admin`, Store=`store`; never infer from serial/UI |
| `origin_user_id` | nullable FK users; index | Store authentication actor independent of staff lifecycle actor | Existing rows remain null; nullOnDelete preserves orders |
| `client_request_id` | nullable string(100); unique `(origin,origin_user_id,client_request_id)` | Actor-scoped Store retry idempotency before every side effect | Existing/Admin nulls coexist; normalized bounded input; Admin need not provide it |

The idempotency lookup always includes the authenticated `origin_user_id`; a collision under another
User neither conflicts nor returns that order. Because MySQL permits multiple NULLs, Admin and
historical rows remain compatible. Store writes require non-null actor/request ID on the native
checkout path. The legacy adapter stores a server UUID on the order and uses the expiring compatibility
attempt registry for its two-minute actor-scoped window; this is bounded duplicate mitigation, not
strict retry idempotency for unchanged legacy clients.

No `online_store_order_id`, Store price column, Store debt column, or duplicated delivery/status field
is introduced.

## Derived values (never persisted as authority)

- Listing availability: Product/variant stock minus existing reservation semantics.
- Final price: authoritative base price minus one eligible promotion and optionally one eligible coupon
  under V1 precedence; the accepted order item stores the resulting immutable snapshot.
- Current debt: sum/reconciled result of active authoritative ledger transactions by party/currency.
- Available credit: `credit_limit - applicable outstanding ledger exposure`, never below zero.
- Verified purchase: existence of an authorized completed/non-reversed SalesOrder item for the linked
  customer and Product.
- Dashboard/report aggregates: query-time or cacheable projections that always reconcile back to
  listings, SalesOrders, redemptions, reviews, stock, and debt transactions.

## Deletion and retention

- Product/party/order deletion is restricted where Store history depends on it; existing soft-delete
  behavior is honored.
- Listings/categories/promotions/coupons/settings are disabled/hidden instead of hard-deleted after
  use. Historical redemptions, reviews, and audit events are retained.
- Presentation rows may cascade with a listing only before business history exists; implementation
  should prefer lifecycle hiding for production records.
- Account link deactivation does not delete the User, customer/seller, orders, debts, or audits.

## Migration verification checklist

1. Compare generated SQL against the dump's verified column types/collations and actual FK names.
2. Prove absence of every new table/column before creation; guard retry-safe migration behavior where
   project conventions require it.
3. Backfill every pre-migration production `sales_orders` row to `origin=admin` per the confirmed
   business rule; verify counts/checksums and do not inspect serial formatting. Then enforce non-null.
4. Run duplicate analysis before the actor-scoped request unique constraint; verify identical request
   IDs under different Users coexist and same-User duplicates cannot.
5. Verify fresh-migration and dump-derived schemas because media migration names differ from production.
6. Assert rollback does not delete core or financial data; post-rollout rollback disables exposure and
   retains new history rather than dropping it.
