# Contract: Store Checkout to Existing SalesOrder Flow

## Required request identity

The protected Store order operation derives the User from a valid Store Sanctum token. Request body
user/customer/seller IDs are not authoritative. Each native submission includes a stable
`client_request_id` generated once per customer checkout attempt. Its ownership scope is the tuple
`(origin=store, origin_user_id=<authenticated user>, client_request_id)`.

Conceptual input:

```json
{
  "client_request_id": "bounded-stable-id",
  "account_role": "customer",
  "items": [
    {"listing_id": 10, "size_id": null, "size_color_id": null, "quantity": 2}
  ],
  "coupon_code": "OPTIONAL",
  "payment": {"type": "cash|credit|mixed", "paid_amount": 500},
  "delivery": {"partner_address_id": 4, "delivery_company_id": 1}
}
```

Client-supplied item price, discount, total, available credit, debt balance, ownership, verified
purchase, or stock availability is ignored/rejected.

## Transactional orchestration

1. Resolve bearer User and active `online_store_account_links` role.
2. Before any stock, coupon, payment, debt, accounting, notification, or status side effect, lock/find
   `(origin=store, origin_user_id=authenticated User, client_request_id)`. Return an existing accepted
   order only from that exact actor scope. Another User's identical ID neither conflicts nor reveals it.
3. Resolve every listing to its Product/variant; require published/complete and purchasable stock.
4. Read authoritative retail/wholesale price for the resolved party context.
5. Evaluate one promotion and optional coupon under deterministic precedence.
6. Recalculate subtotal, delivery, discounts, and total server-side.
7. For credit/mixed payment, lock policy scope, require explicit eligibility, calculate debt/available
   credit from the existing ledger, and reject an excess before any write.
8. Reserve coupon use and invoke an extended trusted Store entry point on `SalesOrderService::store`.
9. Persist `origin=store`, `origin_user_id`, and `client_request_id`; retain server price/discount
   snapshots in existing order/order-item fields.
10. Continue through existing confirmation, stock, fulfillment, settlement, debt, accounting,
    delivery, Shiply, notification, and status-log services at the appropriate lifecycle events.
11. Commit order and coupon redemption together. Roll back all Store-owned writes on failure.

## Existing-service extension contract

- `SalesOrderService` keeps current Admin behavior. Store calls use a server-priced command/adapter
  that cannot be populated directly from request price fields.
- `SalesOrderStockService` remains the only reservation/dispatch/release authority.
- `SalesOrderFulfillmentService` remains the payment, delivery, cancellation/reversal orchestrator.
- `DebtLedgerService::syncSalesOrderToLedger` resolves either:
  - customer order -> `customer_id`, `seller_id=null`; or
  - supplier order -> `customer_id=null`, `seller_id` from validated order partner.
- `AccountingProjectionService` uses the same party dimension in deposits, receivable effects,
  settlements, reversals, and metadata. Journal `source_key` idempotency remains unchanged.

## Required outcomes

- Retry with the same accepted request ID returns the same SalesOrder and creates no additional stock,
  settlement, coupon, debt, journal, notification, or status effect.
- Two authenticated Users may use the same request ID independently. Admin-origin calls remain
  backward compatible and are not required to supply `client_request_id`.
- A 2,000 order with 500 paid posts one 500 payment and one 1,500 unpaid ledger amount for either an
  eligible customer or supplier, with matching accounting projection.
- Zero stock or a stock race prevents acceptance; V1 never back-orders.
- Order history for a linked User includes authorized Admin- and Store-origin SalesOrders.
- Cancellation/reversal releases applicable coupon reservation under the documented business rule and
  invokes existing stock/financial reversal paths.

## Legacy request compatibility

The inspected Flutter Store request has no stable attempt identifier. Its existing fields include
items/variants/quantities, address/city/village, mutable client prices/totals, a client timestamp, and
request-supplied user IDs. Strict retry idempotency is impossible without changing that client because
an identical later submission may be an intentional new purchase.

Until the client generates one UUID per checkout attempt, the compatibility adapter uses a server
fingerprint over: compatibility protocol version, authenticated User ID, canonical sorted
Product/variant quantities, normalized coupon code, canonical delivery city/village/address, and
payment intent. It excludes price, discount, total, timestamp, and request-supplied identity. Under an
actor-scoped lock, `online_store_legacy_checkout_attempts` maps that fingerprint to the accepted order
for two minutes; the order itself gets a separate server-generated `legacy:<uuid>` request ID. A match
from another account is impossible. After expiry the locked registry row is replaced and the same
payload is treated as new without colliding with the SalesOrder unique index. This
prevents concurrent/near-retry duplication but does not satisfy indefinite retry idempotency. Full
FR-031 behavior starts when the client supplies its stable attempt UUID.
