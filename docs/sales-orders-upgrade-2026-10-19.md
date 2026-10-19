# Sales order update - 2026-10-19

The supplied SQL dump was inspected as text only. It was neither imported nor edited.
The October 19 snapshot contains nine orders: six unconfirmed, two canceled, and one
with delivery. Unconfirmed order 37 (0000077) has a coupon redemption and order 38
(0000078) has a promotion redemption. Both foreign keys restrict deletion. The old
delete method removed neither relation and returned a generic error.

## Deletion

- Ordinary deletion now snapshots exactly one unconfirmed order before releasing
  its reservation and removing its Store discount/legacy checkout links.
- Forced deletion requires an administrator, password and reason. It uses the
  existing stock/cash/debt reversal helpers, within the same transaction as the backup.
- Linked invoices, financial returns and child orders remain protected. Confirmed
  or delivered orders cannot be removed through this endpoint.
- Files are retained for restoration. Backups appear in the existing orders backup
  screen. Restoring a draft rechecks stock availability and coupon usage capacity.
- Neither individual deletion nor its restoration resets serial counters.
- `GET sales/order/delete-preview` returns eligibility and effects before confirmation.

## Notes and images

Run the new `2026_10_19_200000_create_sales_order_notes.php` migration on the server
before deploying the updated app. No migration was executed locally.

`POST sales/order/note` accepts `sales_order_id`, `scope` (`internal` or `external`),
`note` and up to five `images[]`. Text or at least one image is required. Images are
JPEG/PNG/WebP, up to 8 MB each. Existing `sales_orders.notes` text is preserved.
Both scopes are staff notes. They are omitted from customer PDFs unless staff
explicitly select the option to include notes and their pictures.

Creation/update can send `order_data` JSON plus `note_images[]` in one multipart
request. The order and its internal note images commit together. Failed storage or
database work rolls back the record changes and removes newly stored files.

## Flutter PDF and sharing

The current Flutter UI loads the requested order and generates its PDF locally;
it does not call the legacy DomPDF statement endpoint. The old endpoint remains
available for compatibility. Hidden item rows are excluded, and equal product,
variant and price rows are merged. Product images and notes are optional.

Android sharing attaches the PDF through a narrowly scoped FileProvider and targets
the selected installed WhatsApp package. The recipient can be the invoice customer
or another number. WhatsApp can require recipient confirmation; opening its composer
does not establish that a message was sent. iOS uses the system share sheet for app
and recipient selection.

## Verification boundaries

- Pure PHP policy tests: no Laravel application boot and no database connection.
- PHP syntax/style checks on the changed files.
- Flutter widget, PDF, transport and numeric regression tests; targeted analysis.
- Visual review uses a synthetic multipage invoice, with no customer data.
- Live deletion/restore, server migration and real WhatsApp delivery are not covered
  by local static or mocked tests. They require server/device verification.
