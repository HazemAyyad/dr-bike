# Contract: `routes/api_store.php` Compatibility

## Stability promise

All existing route paths, HTTP methods, legacy field names, top-level envelopes, and documented status
semantics remain available during this feature. Internal delegation may change only after fixture-based
contract tests prove compatibility. No `/api/store/v2` migration is included.

Covered groups:

- `Auth/*`, `Users/*`, `Settings/CheckSetting`
- `OnlineAds/GetAllAds`, Notifications, Comments
- MainCategorys/SupCategorys reads
- Items discovery/detail reads
- Cities and delivery fee reads
- Orders manage/cancel/history reads

## Compatibility adapter rules

- Public catalog methods may retain legacy inventory-based output until the new listing presentation
  can be mapped without breaking current clients. New Admin taxonomy MUST NOT change physical
  `store_sections` or legacy category data.
- When Store-managed listings are used, adapters translate them back to existing names such as
  `nameAr`, `normailPrice`, `itemSizes`, and legacy row/pagination envelopes.
- Order creation returns the existing legacy order payload but delegates authority to the checkout
  contract. Submitted legacy price/discount/total fields are never authoritative.
- The current legacy order body has no stable attempt ID. The adapter uses the documented two-minute,
  authenticated-actor/canonical-payload fingerprint only for concurrent/near-retry mitigation. It
  cannot guarantee late retry idempotency; full guarantees require a client-generated checkout UUID.
- Order history resolves the authenticated token's linked party and includes authorized orders across
  origins. Request `userId` may be accepted for wire compatibility but MUST match resolved ownership.
- Cancel requests invoke the existing SalesOrder cancellation lifecycle and ownership checks rather
  than directly updating status.
- `Settings/CheckSetting` maps typed Store settings to the existing `isClose/message/call/whatsApp`
  response fields.
- Comments map to reviews while preserving current empty/success envelopes until the client can
  consume populated rows safely.

## Security corrections allowed within compatibility

- Protected user/order mutations require a valid Store bearer token and server ownership even if the
  old route previously trusted an ID.
- Password reset is an explicit client migration dependency. Current Flutter parses the response
  `otp`, compares it locally, and resets with `userId` plus new passwords only; therefore a secure
  server-only correction cannot preserve the successful flow unchanged.
- The minimum client change accepts a generic forgot-password response, submits email+OTP for server
  verification, receives an opaque short-lived single-use account-bound reset proof, and resets using
  that proof. Server OTP storage is hashed with expiry/rate/attempt limits. Forgot responses avoid user
  enumeration; OTP/password/reset proof is never returned after verification or logged. Do not retain
  OTP leakage or userId-only reset for compatibility, and gate secure-flow activation on client rollout.
- Authentication and authorization failures may become stricter (`401/403/404`) without changing
  successful payload shapes.

## Frozen fixtures before delegation

Capture representative request/response fixtures for every route, including multilingual products,
variants, all media types, categories, cities, order creation/history/cancel, settings, errors, and
empty lists. Tests compare keys, types, nesting, status codes, and fallback values. Never store real
credentials, tokens, phone numbers, or production personal data in fixtures.

Security fixtures additionally prove cross-account request IDs never match, legacy near-retries do
not duplicate side effects, post-window identical orders are allowed, forgot responses are generic,
OTP verification is account-bound/rate-limited/single-use, and reset proof cannot cross accounts.

## Rollback

A configuration-controlled adapter switch may temporarily return reads to the old controller query
path during rollout. It must never re-enable insecure direct order writes after the new transactional
checkout path has accepted orders. New financial/order history remains in core tables.
