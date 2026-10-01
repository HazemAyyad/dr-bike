# Feature Specification: Online Store Management

**Feature Branch**: `feat/online-store-management`

**Created**: 2026-10-01

**Status**: Ready for planning

**Input**: User description: "Create a Doctor Bike Admin management layer for the customer-facing
online store while preserving existing inventory, order, identity, debt, accounting, permissions,
media, notification, stock, and legacy Store behavior."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Curate Inventory Products for the Store (Priority: P1)

An authorized store manager selects an existing inventory product, creates its online presentation,
assigns it to one or more online categories, completes customer-facing content and media, and
publishes it without creating another inventory product.

**Why this priority**: A controlled, accurate online catalog is the foundation for every other store
capability and delivers value independently of promotions or advanced content.

**Independent Test**: Select one existing product, prepare a complete listing, publish it, and verify
that customers see the presentation while inventory identity, price source, and stock remain linked
to the original product.

**Acceptance Scenarios**:

1. **Given** an existing product without a listing, **When** an authorized manager creates a draft,
   completes required content, and publishes it, **Then** one online listing references that product
   and becomes customer-visible without creating a duplicate product.
2. **Given** a product already has a draft, ready, published, or hidden listing, **When** a manager
   returns to manage that product online, **Then** the same listing is updated and creation of another
   listing for that product is rejected.
3. **Given** a listing missing mandatory content, **When** publication is requested, **Then**
   publication is blocked and the manager sees every missing requirement.
4. **Given** a published listing, **When** the manager hides it, **Then** it disappears from customer
   discovery while the inventory product and its stock remain unchanged.
5. **Given** translated display content is missing for a requested language, **When** the listing is
   viewed, **Then** the configured fallback value from the authoritative product is shown.

---

### User Story 2 - Organize Storefront Discovery (Priority: P1)

An authorized content manager builds an online-only category hierarchy, assigns listings to multiple
categories, configures home sections and banners, and controls their order and visibility.

**Why this priority**: Customers need usable navigation and curated entry points to discover the
published catalog.

**Independent Test**: Create a category hierarchy and a home section, assign published listings,
activate a scheduled banner, and verify customer-visible order, language, and navigation behavior.

**Acceptance Scenarios**:

1. **Given** active parent and child online categories, **When** a listing is assigned to both valid
   categories, **Then** customers can discover it through each assigned path.
2. **Given** a hidden home section, **When** customers open the store home, **Then** that section and
   its content are not displayed.
3. **Given** a banner outside its active date range, **When** customers open the store, **Then** the
   banner is not displayed.
4. **Given** a banner action targeting a product, category, offer, or URL, **When** a customer selects
   it, **Then** the configured valid destination is opened; an invalid destination is not exposed.

---

### User Story 3 - Present Accurate Media, Price, and Stock (Priority: P1)

A store manager controls which existing product media appears online and how it is ordered, while
customers always receive an authoritative price and an accurate stock presentation.

**Why this priority**: Incorrect price or availability creates financial and customer-service risk;
clear imagery is essential to purchase decisions.

**Independent Test**: Reorder and hide selected media for one listing, adjust authoritative source
price or stock, and verify the online presentation updates without deleting inventory media or
allowing stock edits through store management.

**Acceptance Scenarios**:

1. **Given** a product has multiple media items, **When** a manager chooses a main image, reorders
   items, and hides one online, **Then** the Store reflects that presentation and the source media is
   not deleted.
2. **Given** price, promotion, and customer type inputs, **When** a customer views or orders an item,
   **Then** the displayed and charged values follow the authoritative pricing decision.
3. **Given** inventory stock changes, **When** the listing is viewed, **Then** its availability follows
   the configured out-of-stock policy and store management provides no stock-editing action.

---

### User Story 4 - Run Promotions and Coupons Safely (Priority: P2)

An authorized promotions manager schedules retail or wholesale promotions and issues constrained
coupon codes while the system validates eligibility and recalculates every order total.

**Why this priority**: Promotions improve conversion but must not override authoritative pricing or
permit invalid discounts.

**Independent Test**: Configure one promotion and one coupon with dates, limits, and targeting, then
exercise eligible, ineligible, expired, and exhausted redemption cases.

**Acceptance Scenarios**:

1. **Given** an active targeted promotion, **When** an eligible customer views an included listing,
   **Then** the correct discount and validity period are visible and reflected in the final price.
2. **Given** a coupon that is expired, exhausted, below its minimum order, outside its targeting, or
   over the user's limit, **When** redemption is attempted, **Then** it is rejected with a clear reason.
3. **Given** client-submitted price or discount values differ from authoritative values, **When** the
   order is evaluated, **Then** the submitted values are ignored and totals are recalculated.

---

### User Story 5 - Manage Store Orders and Linked Identities (Priority: P1)

Authorized staff distinguish Store-origin orders from Admin-origin orders while all orders continue
through the existing order lifecycle, and a linked customer or supplier account sees every relevant
order it is authorized to see.

**Why this priority**: Ordering must preserve a single operational record and give business parties a
complete, correctly authorized history.

**Independent Test**: Create orders through Store and Admin origins for the same linked business
identity and verify origin reporting, visibility, lifecycle behavior, and cross-account isolation.

**Acceptance Scenarios**:

1. **Given** a Store customer submits an order, **When** it is accepted, **Then** an existing-domain
   sales order is created with explicit Store origin and normal stock, finance, delivery, status-log,
   and notification behavior.
2. **Given** a linked user has Admin-origin and Store-origin sales orders, **When** viewing history,
   **Then** all relevant authorized orders are shown regardless of origin.
3. **Given** a user requests another party's order, **When** ownership and permissions are evaluated,
   **Then** access is denied without disclosing protected order data.
4. **Given** an existing customer or supplier receives Store access, **When** linking completes,
   **Then** no duplicate business identity is created and the account roles are explicit.

---

### User Story 6 - Purchase on Approved Credit (Priority: P2)

An eligible linked business party places an order with full or partial payment, and the unpaid amount
is recorded through the authoritative debt and accounting process.

**Why this priority**: Credit purchasing is valuable to business customers but carries direct
financial integrity risk and therefore follows core ordering.

**Independent Test**: For an eligible account, complete a 2,000 order with a 500 payment and verify
that the order shows 500 paid and the authoritative ledger records 1,500 unpaid exactly once.

**Acceptance Scenarios**:

1. **Given** an account eligible for credit within policy, **When** a 2,000 order receives 500 payment,
   **Then** 1,500 is posted to the existing debt/accounting ledger and no separate wallet is created.
2. **Given** an account is ineligible or exceeds its approved limit, **When** credit checkout is
   attempted, **Then** it is blocked before financial posting with a clear reason.
3. **Given** an order submission is retried, **When** the original financial posting already exists,
   **Then** no duplicate debt, payment, stock, or accounting effect is recorded.

---

### User Story 7 - Moderate Reviews and Communicate (Priority: P3)

An authorized manager moderates customer reviews and sends operational or marketing notifications
using existing communication capabilities.

**Why this priority**: Trust and engagement enhance the store after catalog and ordering flows work.

**Independent Test**: Submit and moderate a review, then issue an order-status and a marketing
notification and verify visibility, authorization, and destination metadata.

**Acceptance Scenarios**:

1. **Given** a customer review is pending, **When** an authorized manager publishes or rejects it,
   **Then** its state changes with an audit trail and only published reviews are customer-visible.
2. **Given** purchase evidence exists for the reviewer and product, **When** verified-purchase status
   is evaluated, **Then** it is derived from that evidence rather than accepted from the client.
3. **Given** an order-status notification template, **When** an applicable order transition occurs,
   **Then** the intended authorized account receives the notification with valid destination metadata.

---

### User Story 8 - Govern and Measure the Store (Priority: P2)

Authorized administrators configure store operating rules, see a status dashboard, review reports,
and investigate sensitive changes without receiving access outside their granted permissions.

**Why this priority**: Operations require control, observability, and accountability once the core
store is active.

**Independent Test**: Configure operating settings under distinct permission sets, perform audited
changes, and reconcile dashboard/report figures against known listings, orders, and ledger activity.

**Acceptance Scenarios**:

1. **Given** an administrator lacks a granular store permission, **When** the related action is
   requested directly, **Then** it is denied even if a client displays the control.
2. **Given** authorized publication, promotion, coupon, settings, identity-linking,
   review-moderation, or other pricing-affecting Store changes, **When** they complete, **Then** an
   audit record identifies actor, action, entity, timestamp, and relevant old/new values without
   treating base product-price editing as an Online Store Management capability.
3. **Given** known listings, promotions, reviews, and orders, **When** the dashboard is viewed, **Then**
   each count and status summary reconciles with the underlying authoritative records.
4. **Given** a report period and filters, **When** a report is requested, **Then** Store sales, origin
   counts, best sellers, stock status, average order value, customer type, and credit activity are
   reported consistently without treating unknown values as zero.

### Edge Cases

- The referenced inventory product or business identity is archived, merged, or removed while a
  listing, review, coupon target, banner action, or account link still references it.
- A parent category is disabled while child categories or published listings remain active.
- A scheduled promotion, coupon, or banner crosses a daylight-saving change or has an end before its
  start; validity follows the store's configured business timezone and invalid ranges are rejected.
- Multiple discounts target the same line; the customer is shown the single authoritative outcome
  and the applied promotion/coupon evidence.
- A product has variants with mixed availability, or stock reaches zero between display and order
  confirmation; final availability is revalidated before acceptance.
- A listing is published and later loses required content, usable media, an active category, valid
  price, or a valid product reference; it becomes incomplete and follows the approved visibility rule.
- Media selected as the main image is removed or becomes unavailable; the listing is flagged
  incomplete and does not expose a broken presentation.
- A coupon reaches its global or per-user limit during concurrent checkout attempts; no redemption
  exceeds the configured limit.
- An order retry, status retry, or payment retry occurs after a partial success; stock and financial
  effects remain single and reconcilable.
- One authentication account is linked to both customer and supplier roles, or an attempted link
  conflicts with an existing business identity; role and ownership checks remain explicit.
- Maintenance mode, disabled checkout, or a globally disabled store is activated while customers
  have active sessions or carts; browsing and checkout follow the configured settings consistently.
- Legacy Store consumers omit newly introduced fields; their existing supported journeys continue
  without requiring an immediate client update.

## Requirements *(mandatory)*

### Functional Requirements

#### Catalog and Merchandising

- **FR-001**: The system MUST allow at most one online-store listing for each existing inventory
  product without creating or copying the product itself. The same listing MUST move through draft,
  ready, published, and hidden states; additional historical or hidden listings for that product MUST
  NOT be created unless a future approved specification introduces listing versioning.
- **FR-002**: A listing MUST support draft, ready, published, and hidden lifecycle states with valid,
  auditable transitions.
- **FR-003**: The system MUST evaluate and expose listing completeness separately from lifecycle state
  and list each missing mandatory item.
- **FR-004**: Publication MUST be blocked unless the listing has a valid source product, display name,
  usable main image, at least one active online category, an authoritative sellable price, and a
  purchasable or explicitly allowed out-of-stock presentation.
- **FR-005**: Listings MUST support multilingual display names and descriptions and use existing
  product values as the fallback when online-specific translations are absent.
- **FR-006**: Listings MUST support multiple online categories, sort order, an optional badge, and
  independently configurable featured, new, home, and offer merchandising flags.
- **FR-007**: Online categories MUST be independent from inventory categories and physical store
  locations and MUST support hierarchy, multilingual content, image, active state, sort order, and
  home visibility.
- **FR-008**: Disabling or removing a category MUST NOT delete its products and MUST produce a clear,
  deterministic effect on affected listing visibility and readiness.

#### Media, Pricing, Promotions, and Stock

- **FR-009**: Managers MUST be able to select a listing's main image, reorder eligible media, and hide
  media from the Store without deleting or changing its inventory use.
- **FR-010**: Video, 3D, and 360 presentation metadata MUST be accepted only when the existing media
  capability can represent and safely deliver that media type.
- **FR-011**: Store-specific media MUST be introduced only for a documented presentation need that
  existing product media cannot satisfy, and MUST remain associated with the source product/listing.
- **FR-012**: Final price, promotion eligibility, discount, and order total MUST be calculated from
  authoritative business data at display and order confirmation time.
- **FR-013**: Online listings MUST use existing retail and wholesale pricing as source data.
- **FR-014**: The first release MUST NOT let Online Store Management edit or override authoritative
  base retail or wholesale product prices. Those existing prices MUST remain the base prices, and
  promotions and coupons MUST be the only supported Store discount mechanisms.
- **FR-015**: Promotions MUST support percentage or fixed discounts, start/end times, target scope,
  active state, and retail/wholesale applicability.
- **FR-016**: Invalid, overlapping, or concurrent promotions MUST resolve through one documented and
  consistently applied pricing precedence rule without producing a negative price.
- **FR-017**: Store Management MUST read product and variant availability from authoritative stock and
  MUST NOT provide stock-editing capability.
- **FR-018**: Each listing MUST provide an authorized navigation reference to its inventory product.
- **FR-019**: A published listing with zero available stock MUST remain discoverable by default, MUST
  display an out-of-stock state, and MUST NOT be purchasable. Back-ordering is outside the first
  release.

#### Storefront Content

- **FR-020**: Authorized managers MUST be able to create, reorder, show, and hide home sections with
  multilingual titles and manual or rule-based content selection.
- **FR-021**: Supported home-section purposes MUST include hero content, categories, best sellers,
  recently added products, maintenance, and offers, while allowing equivalent future purposes
  without changing existing sections.
- **FR-022**: Rule-based sections MUST expose the selection rule and exclude listings that are hidden,
  unpublished, incomplete, or otherwise ineligible at viewing time.
- **FR-023**: Banners MUST support a mobile image, multilingual text where supplied, active state,
  start/end time, sort order, and one action of product, category, offer, URL, or none.
- **FR-024**: A banner MUST NOT be customer-visible outside its active period or when its configured
  internal destination is invalid or unauthorized.

#### Coupons and Orders

- **FR-025**: Coupons MUST have a case-insensitively unique code and support percentage or fixed value,
  active period, minimum order, global usage limit, per-user limit, customer/account eligibility,
  product/category targeting, and retail/wholesale applicability.
- **FR-026**: Coupon eligibility and usage limits MUST be revalidated when an order is confirmed, and
  successful redemption MUST be counted no more than once per accepted order.
- **FR-027**: Percentage, fixed-value, promotion, and coupon discounts MUST NOT reduce any applicable
  order amount below zero or exceed the eligible amount.
- **FR-028**: Store-origin orders MUST use the existing authoritative sales-order domain and MUST NOT
  create a separate online-store order record as an alternative source of truth.
- **FR-029**: Each sales order MUST carry a reliable explicit origin sufficient to distinguish Store,
  Admin, and other recognized origins without inference from UI, serial number, or creator alone.
- **FR-030**: Store-origin orders MUST retain existing stock, financial, delivery, Shiply, status-log,
  cancellation, return, and accounting behavior applicable to sales orders.
- **FR-031**: Every order submission and retry MUST prevent duplicate orders, stock effects, payments,
  debt postings, or coupon usage for the same accepted customer action.
- **FR-032**: A linked Store user MUST see all relevant sales orders for their linked business identity,
  regardless of origin, subject to ownership, role, and business visibility rules.

#### Identity, Credit, Reviews, and Notifications

- **FR-033**: Store access MUST link authentication identities explicitly to existing customers,
  suppliers, or both without creating isolated duplicate business identities.
- **FR-034**: Account links MUST record applicable business role and account-creation source where
  required, and MUST reject ambiguous or conflicting links for manual resolution.
- **FR-035**: Every account, order, review, and credit operation MUST validate ownership and permission
  server-side and MUST prevent cross-account disclosure or mutation.
- **FR-036**: Approved suppliers and approved customers MAY purchase on account in the first release,
  but credit MUST require explicit eligibility and MUST never be granted automatically.
- **FR-037**: Credit policy MUST support explicit eligibility and, when configured, a credit limit;
  available credit MUST be evaluated from authoritative ledger balances rather than a Store balance.
- **FR-038**: Partial payments MUST apply through existing payment and accounting behavior, and the
  exact unpaid amount MUST post once to the authoritative debt ledger.
- **FR-039**: Reviews MUST link to an existing product and customer and support pending, published,
  and rejected states, rating, comment, and submission date.
- **FR-040**: Verified-purchase indication MUST be derived from an eligible completed purchase by the
  linked customer and MUST NOT be accepted as a client assertion.
- **FR-041**: Order-status and marketing notifications MUST use existing notification capabilities,
  respect recipient eligibility, and support validated destination metadata where appropriate.

#### Settings, Permissions, Audit, Dashboard, and Reports

- **FR-042**: Store settings MUST cover store enabled state, maintenance mode, checkout enabled state,
  cash on delivery, guest browsing, minimum order, support/WhatsApp information, enabled languages,
  cancellation/return/warranty/terms content, out-of-stock behavior, and low-stock threshold.
- **FR-043**: Operating settings MUST define their precedence so that disabled store, maintenance,
  disabled checkout, and guest-browsing combinations produce predictable customer behavior.
- **FR-044**: Administrative access MUST use distinct existing-system permissions for Online Store
  View, Products Manage, Categories Manage, Content Manage, Promotions Manage, Reviews Manage, and
  Settings Manage.
- **FR-045**: Permission and ownership decisions MUST be enforced for every management read and write,
  regardless of which controls a client displays.
- **FR-046**: Publication changes, promotions, coupons, settings, identity linking, review moderation,
  and other pricing-affecting Online Store actions MUST record actor, action, entity, timestamp, and
  relevant previous/new values. Changes to authoritative base retail or wholesale product prices are
  outside Online Store Management and remain governed by the existing pricing/inventory domain and
  its audit behavior.
- **FR-047**: The dashboard MUST report counts for published, draft, hidden, incomplete, and
  out-of-stock listings; Store-origin order summaries; active promotions; coupons; and pending reviews.
- **FR-048**: Reports MUST support a defined period and relevant filters for Store sales, Store-origin
  order counts, best-selling online products, out-of-stock listings, average order value,
  retail/wholesale activity, and Store-generated debt/credit activity.
- **FR-049**: Dashboard and report values MUST be derived from authoritative records, disclose applied
  filters and time basis, and distinguish unavailable/unknown values from numeric zero.

#### Compatibility and Safety

- **FR-050**: Existing supported legacy Store journeys MUST remain operational during rollout without
  requiring an immediate customer-app update.
- **FR-051**: Existing Admin and Store consumers MUST continue to accept compatible responses and
  behavior; new required client fields or destructive changes are outside this feature.
- **FR-052**: Existing product, inventory category, physical location, stock, sales-order, customer,
  supplier, debt, accounting, permission, media, and notification records MUST NOT be repurposed or
  duplicated as competing authoritative Store data.
- **FR-053**: New merchandising records MUST reference authoritative business entities and preserve
  historical attribution when a referenced entity later changes state.
- **FR-054**: All external input MUST be validated, sensitive data MUST be excluded from user-visible
  errors and audit payloads, and unauthorized records MUST not be discoverable through identifiers.

### Key Entities *(include if feature involves data)*

- **Online Store Listing**: Customer-facing merchandising presentation linked to one authoritative
  product; contains lifecycle, completeness, translations, flags, badge, order, and presentation data.
- **Online Store Category**: Hierarchical, multilingual navigation concept independent of inventory
  categories and physical locations; may contain many listings.
- **Listing Category Membership**: Assignment and ordering relationship between a listing and one of
  multiple online categories.
- **Store Media Presentation**: Selection, visibility, main-item choice, and order of eligible media
  for a listing without replacing source product media.
- **Promotion**: Time-bound percentage or fixed pricing rule with target and customer-type scope.
- **Coupon**: Unique redeemable discount rule with dates, minimums, targeting, eligibility, and global
  and per-user limits.
- **Home Section**: Ordered, visible, multilingual storefront block with manual or rule-based content.
- **Banner**: Scheduled, ordered visual message with an optional validated destination action.
- **Store Account Link**: Explicit relationship between an authentication identity and an existing
  customer and/or supplier, including roles and creation origin where relevant.
- **Sales Order Origin**: Explicit classification on the authoritative sales order used for operations,
  customer history, dashboard summaries, and reporting.
- **Credit Policy**: Eligibility and optional limit configuration; it does not store authoritative debt.
- **Review**: Customer rating and comment linked to a product and customer with moderation state and
  derived verified-purchase status.
- **Store Setting**: Governed operating, checkout, language, support, policy, and stock-presentation
  configuration.
- **Store Audit Event**: Attribution of a sensitive management action, affected entity, time, and
  relevant before/after values.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An authorized manager can take an eligible existing product from selection to a complete
  published listing in under 5 minutes, excluding content-authoring time.
- **SC-002**: In acceptance testing, 100% of publication attempts missing mandatory listing data are
  blocked and identify all missing items in one review.
- **SC-003**: Across catalog and order acceptance tests, 100% of displayed availability, accepted
  quantities, final prices, discounts, paid amounts, and debts reconcile with authoritative records.
- **SC-004**: In compatibility regression testing, 100% of documented critical legacy Store journeys
  and unaffected Admin journeys continue to complete without a mandatory client upgrade.
- **SC-005**: In authorization testing, 100% of covered cross-account and missing-permission attempts
  are denied without revealing protected business data.
- **SC-006**: For every accepted Store-origin order in reconciliation testing, exactly one sales order
  exists and stock, payment, coupon, debt, and accounting effects occur no more than once.
- **SC-007**: A 2,000 order with a valid 500 partial payment produces a reconciled paid amount of 500
  and authoritative debt of exactly 1,500, with no separate Store balance.
- **SC-008**: Dashboard and report totals reconcile exactly with their stated filters and source
  records for the acceptance dataset, with unavailable metrics explicitly identified.
- **SC-009**: At least 90% of representative Admin users complete listing publication, category
  organization, promotion setup, review moderation, and settings tasks on the first attempt without
  assistance during usability acceptance.
- **SC-010**: For a catalog of 10,000 listings, 95% of user catalog searches, filters, dashboard views,
  and report-opening actions present usable results within 2 seconds under agreed normal load.
- **SC-011**: 100% of sampled sensitive management changes contain complete actor, action, entity,
  timestamp, and applicable before/after audit information.
- **SC-012**: No acceptance scenario creates duplicate authoritative products, business identities,
  orders, debts, stock balances, or accounting balances.

## Assumptions

- The first release manages backend Store behavior and Admin-facing capabilities; rewriting the
  customer Store application and redesigning unrelated Doctor Bike modules are out of scope.
- Existing inventory products, pricing, variants, stock, customers, suppliers, sales orders, debt,
  accounting, permissions, media, delivery, notifications, and status history remain available and
  authoritative.
- Online categories are purely merchandising concepts and do not change inventory classification or
  physical-location behavior.
- Multilingual content uses the store's enabled languages and a deterministic fallback to existing
  business content; translation authoring or automated translation is outside scope.
- Manual and rule-based home selections include only customer-visible eligible listings at viewing
  time; initial rule definitions are finalized during planning from approved business use cases.
- Guest browsing does not imply guest checkout; authenticated ownership remains required for order
  history, review attribution, coupon per-user limits, and credit purchasing.
- Promotion stacking is disabled by default unless a later approved rule explicitly defines allowed
  combinations and precedence.
- Listing-specific online price overrides and back-ordering are outside the first release.
- Approved customers and suppliers may receive credit only after explicit eligibility is granted;
  any configured credit limit, current debt, and available credit are evaluated against the
  authoritative existing ledger.
- Store-specific media is exceptional and is not required for the initial release unless planning
  proves an existing-media limitation.
- Future versioned Store endpoints, legacy API migration, and legacy API removal are outside scope;
  only additive preparation that preserves current behavior may be planned.
- The configured Doctor Bike business timezone governs promotion, coupon, and banner validity.
