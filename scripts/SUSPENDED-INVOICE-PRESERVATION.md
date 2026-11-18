# Suspended invoice preservation

Suspended invoices now keep a database archive of their full contents on creation,
update, completion, cancellation, and note changes. Each revision contains the
actor ID and name, the before and after states, the original payload, and readable
product, variant, and package contents. Names and quantities remain available even
when the catalog changes later. Revision rows are not cascade-deleted with invoices.

All mutations and their archives run in one transaction. If archive storage or
sale creation fails, the suspended invoice remains unchanged. Updates and completion
lock the invoice and compare the version opened by the client before applying edits.

## API

- Invoice list/detail responses include `payload_hash`.
- Send that value as `expected_payload_hash` when resaving or completing an invoice.
- A stale hash rejects the operation; reopen the invoice instead of retrying an old cart.
- Legacy requests without a hash can use unchanged contents, but cannot replace them.
- Replacing a saved buyer additionally requires `confirm_buyer_change: true`, after
  the application's explicit confirmation showing the original and current buyers.
- `GET /api/suspended/instant/sale/history?suspended_instant_sale_id=ID` returns the
  revision history under the same invoice visibility rules, including private auto drafts.

The Flutter application clears the suspended invoice association when resetting
a sale and establishes the association only after a reset while resuming. It also
fetches the current invoice before resuming and forwards its hash with edits.

## Rollout

1. Back up the production database and code using the existing release procedure.
2. Deploy the backend and run
   `php artisan migrate --force --path=database/migrations/2026_11_18_120000_create_suspended_instant_sale_revisions_table.php`.
3. Distribute the matching Doctor Bike admin application update. Older apps may
   need updating before modifying suspended contents; creating new sales remains available.
4. Verify a test suspended invoice retains its original and edited contents in history.

No production deployment is performed by this change. Existing invoices get their
current before-state archived on the next mutation. Already overwritten historical
contents cannot be reconstructed by this migration. History is available through
the authorized API; a dedicated application history/export screen is not part of this change.

## Validation

Run `SuspendedInstantSaleIntegrityTest`, `SuspendedInstantSaleServiceTest`,
`InstantSaleBonusTest`, and `ClosedDayInstantSaleDebtEditTest` on a disposable database.
Flutter coverage: `suspended_invoice_integrity_test.dart` and
`sales_numeric_payload_test.dart`, plus analysis of the changed sales files.
