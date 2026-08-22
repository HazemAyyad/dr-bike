# DR BIKE Purchasing V2 Completion Report

Date: 2026-08-22

## Repositories And Rollback

- Backend: `F:\laragon\www\doctor-bike`
- Flutter admin: `F:\flutter_projects\doctorbike`
- Continuation branch in both repos: `purchasing-v2-completion-20260822-1436`
- Backend baseline before this continuation: `e08cad6` (`Merge purchasing inventory v2`)
- Flutter baseline before this continuation: `7301076` (`Merge purchasing inventory v2`)
- Backend rollback tag: `backup/pre-purchasing-v2-completion-20260822-1436`
- Flutter rollback tag: `backup/pre-purchasing-v2-completion-20260822-1436`

No GitHub push was performed.

## Commits Created

Backend:

- `e95367b` - Phase 04 - expose purchase amanat and discrepancies APIs
- `f7e5e32` - Phase 06 - add manual purchase payment allocation
- `1848825` - Phase 08 - harden costing snapshots and expand purchasing tests

Flutter:

- `c6ab93f` - Phase 01 - rebuild purchase creation UX
- `6cf4334` - Phase 02 - connect purchase price intelligence
- `f6d8006` - Phase 03 - add reviewed item receiving UX
- `6cff1ee` - Phase 04 - add purchase amanat discrepancies dashboard
- `ec6898e` - Phase 05 - expose full purchase activity timeline
- `87c3b1c` - Phase 06 - add manual purchase payment allocation UI
- `f2478f4` - Phase 07 - rebuild purchase return creation UX
- `feb76a9` - Phase 09 - fix release build navigation tile

Current HEADs:

- Backend: `1848825f49aeea090f292a4261735252cbb004c0`
- Flutter: `feb76a97b14f7bbd6c27ba9692f5cc9876181b0c`

## Backend Changes

- Added `GET /purchase/amanat` for global custody stock visibility.
- Added `GET /purchase/discrepancies` for missing, extra, damaged, and mismatched purchase issues.
- Added `GET /purchase/account/open-bills` for manual account-payment allocation UI.
- Added `purchase_payment_allocations` table and `PurchasePaymentAllocation` model.
- Extended supplier/person account payments with explicit manual allocations.
- Preserved oldest-first allocation, now with allocation records.
- Hardened outbound costing snapshots in `ProductStockService`; costing errors are no longer silently swallowed when cost layers exist and should be consumed.
- Extended backend coverage for partial receiving, mixed receiving issues, manual allocation, customer-as-purchase-source, supplier credit returns, cash refund returns, FIFO, moving weighted average, and instant-sale cost snapshots.

## Flutter Changes

- Rebuilt new purchase creation into a modern purchase-cart flow:
  - unified supplier/customer source selector
  - product search/cards/images
  - quantity and purchase-price editing
  - total summary
  - create purchase without receiving stock
- Connected purchase price intelligence:
  - lowest historical price
  - current source last price
  - latest overall price
  - history sheet with price reuse action
- Replaced normal user-facing auto-receive shortcut with item-by-item reviewed receiving.
- Added receiving rows for accepted, missing, extra/amanat, damaged, mismatched, delivered-now, unit price, notes, and reason.
- Added Purchasing dashboard tabs for invoices, amanat, and discrepancies.
- Added full purchase activity timeline bottom sheet.
- Converted account payment from Seller-only to source/person-aware seller/customer payment.
- Added manual account-payment allocation UI with open invoice rows and allocation amount fields.
- Rebuilt purchase return creation as a dedicated screen instead of legacy `AddNewBillScreen` mode:
  - source selector
  - product search/cards
  - return cart
  - supplier credit vs cash refund
  - required refund box for cash refund
- Fixed an unrelated release-build blocker in Financial Affairs where `MainPageWidget` no longer existed.

## Database Changes

- `database/migrations/2026_08_22_143700_purchase_payment_manual_allocations.php`
  - creates `purchase_payment_allocations`
  - links account payments to selected purchase invoices
  - preserves allocation audit records

The migration was executed with:

```bash
php artisan migrate
```

Result: migration completed successfully.

## Verification

Backend:

- `php -l app\Http\Controllers\API\Bills.php`: passed
- `php -l app\Services\PurchaseAccountService.php`: passed
- `php -l app\Models\PurchasePaymentAllocation.php`: passed
- `php -l app\Services\ProductStockService.php`: passed
- `php -l tests\Feature\PurchasingInventoryV2Test.php`: passed
- `php artisan test --filter=PurchasingInventoryV2Test`: passed, 12 tests, 63 assertions
- `php artisan test`: attempted, timed out after a long run; no failure was reached in the visible output, but this is not counted as passed

Flutter:

- `dart format` was run on changed Flutter files.
- `flutter analyze lib\features\admin\buying`: passed, no issues found.
- `dart analyze lib\features\admin\financial_affairs\presentation\views\financial_affairs_screen.dart`: passed, no issues found.
- `flutter analyze`: attempted, timed out; not counted as passed.
- `flutter test`: attempted, timed out; not counted as passed.
- `flutter build apk`: passed.
- APK output: `F:\flutter_projects\doctorbike\build\app\outputs\flutter-apk\app-release.apk`

## Known Limitations

- Contextual attachments are still mostly invoice/action-generic in the Flutter UX; the backend supports attachable links, but not every action flow has first-class evidence upload yet.
- Damaged and mismatched resolution options are captured as receiving issue quantities/reason/notes, but a complete settlement workflow for every resolution type remains a future hardening area.
- Purchase details were improved with workflow sections and sheets, but not fully refactored into a tabbed information architecture.
- Full project Laravel tests, full Flutter analyze, and full Flutter tests did not complete within command timeouts and should be rerun in a longer CI/local session before deployment confidence is considered complete.

## Rollback

Because this branch has local-only commits, safest rollback for deployment is:

```bash
git switch main
git tag backup/main-before-purchasing-v2-merge-20260822
git merge --no-ff purchasing-v2-completion-20260822-1436
```

If the deployed result is bad, revert the merge commit on `main`:

```bash
git switch main
git revert -m 1 <merge_commit_sha>
```

For pre-continuation rollback:

```bash
git switch purchasing-v2-completion-20260822-1436
git reset --hard backup/pre-purchasing-v2-completion-20260822-1436
```

Use database backups before reverting migrations in any shared or production-like database.
