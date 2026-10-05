# Admin API Contract: Online Store Management

## Boundary

All endpoints are added under the existing authenticated Admin API in `routes/api.php`, use Sanctum,
refresh-token middleware where current Admin routes require it, and enforce the named permission on
the server. Exact URI nesting may follow repository route conventions, but resource semantics and
permission ownership below are fixed.

Base prefix: `/api/online-store`

Standard success envelope:

```json
{"data": {}, "meta": {}}
```

Standard validation/authorization behavior uses existing Laravel API conventions: `422` validation,
`401` unauthenticated, `403` unauthorized, `404` inaccessible/not found, `409` state/concurrency
conflict. A `404` is preferred where revealing resource existence would create IDOR leakage.

## Permission map

| Permission | Reads/writes |
|------------|---------------|
| Online Store View | dashboard, listing/category/content/promotion/review/settings/report reads |
| Online Store Products Manage | listing lifecycle, merchandising flags, memberships, media presentation |
| Online Store Categories Manage | category hierarchy and ordering |
| Online Store Content Manage | home sections, banners, marketing notification composition |
| Online Store Promotions Manage | promotions, coupons, redemption inspection |
| Online Store Reviews Manage | moderation |
| Online Store Settings Manage | settings, account links, credit eligibility/limits |

Administrators retain the existing global bypass behavior. Employees require the explicit permission.

## Resource endpoints

### Dashboard and reports

- `GET /dashboard`: listing counts, Store-origin orders, active promotions, coupon summary, pending reviews.
- `GET /reports?from=&to=&metric=&account_type=&status=`: authoritative filtered report with timezone,
  filters, and explicit Admin/Store origin counts. Historical rows are backfilled to Admin by confirmed
  business rule; no unknown-origin V1 bucket is exposed.

### Listings

- `GET /listings`, `GET /listings/{listing}`
- `POST /listings` body requires `product_id`; conflicts if that Product already has any listing.
- `PATCH /listings/{listing}` updates translations/flags/badge/sort only, never Product price/stock.
- `POST /listings/{listing}/transition` body `{ "status": "ready|published|hidden|draft" }`.
- `PUT /listings/{listing}/categories` replaces validated category memberships atomically.
- `PUT /listings/{listing}/media` replaces Store-only ordered presentation metadata atomically; target
  rows must reference media belonging to the Product and never alter source media.
- `GET /products/{product}/store-readiness` previews source product/media/stock and missing fields.

`POST /listings` initializes all valid Product media deterministically: View Image rows by source ID,
then Normal Image rows by source ID, then 3D Image rows by source ID; the first valid row is the default
Store main image. Inventory has no sort/main field to inherit. A media-less listing can be draft but a
publish transition fails until a usable visible main image exists. Store-specific upload is optional
and exceptional.

Responses expose `base_prices` and `availability` as read-only derived values and include
`readiness_state/readiness_issues`. No request schema accepts a base-price or stock mutation.

### Categories and storefront content

- CRUD/order endpoints for `/categories`, `/home-sections`, and `/banners`.
- Manual section item bodies use `{target_type: "listing|category", target_id, sort_order}`. Product
  sections accept listings; Categories accepts categories. Automatic sections accept only validated
  server allow-listed `selection_config`; hero uses dedicated scheduled banners; maintenance uses its
  validated destination/content configuration. Arbitrary class names and incompatible targets return
  `422`; order is `sort_order,id` after visibility/eligibility filtering.
- Reorder calls accept the complete sibling ID sequence plus an optional version timestamp; missing,
  foreign, or duplicate IDs return `422/409` without partial reorder.
- Category parent changes reject self-parenting and descendant cycles.
- Banner actions validate target existence and customer visibility.

### Promotions and coupons

- CRUD endpoints for `/promotions` and `/coupons`, plus activate/deactivate actions. Promotion bodies
  require `scope=global|targeted`: global has no target rows; targeted must contain at least one valid
  allow-listed listing/category target before activation. Missing rows never imply global scope.
- `POST /pricing/preview` accepts listing/variant IDs, quantity, account context, and optional coupon
  code; response returns base price, selected promotion, coupon, final price, and rejection reasons.
- Preview is advisory; checkout always recomputes.
- Coupon codes are never returned with unrelated sensitive account data.

### Accounts and credit

- `GET /accounts` searches existing Store users and linked party roles.
- `POST /accounts/{user}/links` creates a validated customer or seller link.
- `PATCH /account-links/{link}` changes link status after ownership/conflict validation.
- `PUT /account-links/{link}/credit-policy` sets explicit eligibility, optional limit, currency, and expiry.
- `GET /account-links/{link}/credit` returns policy, ledger-derived current debt, available credit, and
  an `as_of` timestamp; no endpoint writes a current balance.

### Reviews, settings, audit

- `GET /reviews`, `POST /reviews/{review}/moderate` with published/rejected and optional reason.
- `GET /settings`, `PUT /settings` updates one validated configuration atomically.
- `GET /audit-events` filters by entity/action/actor/date and is permission protected.

## Concurrency and audit contract

- Mutations affecting publication, promotions, coupons, settings, identity links, review moderation,
  or other Store pricing outcomes create an audit event in the same transaction.
- Coupon usage and account-link uniqueness use database locks/constraints; conflict returns `409`.
- Update endpoints accept an `updated_at`/version precondition for resources edited concurrently.
- Audit payloads exclude passwords, tokens, OTPs, raw credentials, and unnecessary personal data.
