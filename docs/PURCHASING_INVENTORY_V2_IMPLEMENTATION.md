# DR BIKE Purchasing V2 Completion Report

Date: 2026-08-23

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
- `582ccb7` - Phase 10 - document purchasing v2 completion status
- `2ce64f9` - Phase 11 - add purchase issue resolution workflow
- `8677ee0` - Phase 12 - update purchasing completion report
- `27e65ce` - Phase 14 - harden sanctum token refresh for tests

Flutter:

- `c6ab93f` - Phase 01 - rebuild purchase creation UX
- `6cf4334` - Phase 02 - connect purchase price intelligence
- `f6d8006` - Phase 03 - add reviewed item receiving UX
- `6cff1ee` - Phase 04 - add purchase amanat discrepancies dashboard
- `ec6898e` - Phase 05 - expose full purchase activity timeline
- `87c3b1c` - Phase 06 - add manual purchase payment allocation UI
- `f2478f4` - Phase 07 - rebuild purchase return creation UX
- `feb76a9` - Phase 09 - fix release build navigation tile
- `94544a2` - Phase 11 - add purchase issue resolution UI
- `9fbb7fa` - Phase 12 - add contextual purchase evidence uploads
- `b19050a` - Phase 14 - organize purchase details tabs and add model coverage

Implementation HEADs before this report-only update:

- Backend: `27e65ce`
- Flutter: `b19050a`

## Backend Changes

- Added `GET /purchase/amanat` for global custody stock visibility.
- Added `GET /purchase/discrepancies` for missing, extra, damaged, and mismatched purchase issues.
- Added `GET /purchase/account/open-bills` for manual account-payment allocation UI.
- Added `purchase_payment_allocations` table and `PurchasePaymentAllocation` model.
- Extended supplier/person account payments with explicit manual allocations.
- Preserved oldest-first allocation, now with allocation records.
- Hardened outbound costing snapshots in `ProductStockService`; costing errors are no longer silently swallowed when cost layers exist and should be consumed.
- Added `purchase_issue_resolutions` and `POST /purchase/issue/resolve` for explicit damaged/mismatched settlement decisions.
- Prevented finalization while damaged or mismatched quantities remain unresolved.
- Hardened receiving validation so accepted + missing cannot exceed remaining ordered quantity.
- Hardened Sanctum token expiry refresh so test/auth transient tokens do not break protected API tests while real personal access tokens still refresh expiry.
- Extended backend coverage for partial receiving, mixed receiving issues, issue resolution, manual allocation, customer-as-purchase-source, supplier credit returns, cash refund returns, FIFO, moving weighted average, and instant-sale cost snapshots.

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
- Added Purchase Details actions for damaged/mismatched issue settlement:
  - return to supplier
  - replacement expected
  - accept at negotiated price
  - accept with discount
  - other settlement
- Added contextual evidence upload entry points for damaged/mismatched resolutions, Amanat purchase/return actions, initial purchase payments, later purchase payments, and source/account payments.
- Refactored the purchase details workflow panel into purchase-focused sections/tabs:
  - summary
  - items/receiving
  - discrepancies
  - Amanat
  - payments
  - returns
  - attachments
  - activity
- Added Flutter model coverage for parsing purchase details data that feeds the details tabs, including customer-as-source, item receiving quantities, Amanat, payments, returns, contextual attachments, and timeline entries.
- Fixed an unrelated release-build blocker in Financial Affairs where `MainPageWidget` no longer existed.

## Database Changes

- `database/migrations/2026_08_22_143700_purchase_payment_manual_allocations.php`
  - creates `purchase_payment_allocations`
  - links account payments to selected purchase invoices
  - preserves allocation audit records
- `database/migrations/2026_08_23_091500_create_purchase_issue_resolutions_table.php`
  - creates `purchase_issue_resolutions`
  - records damaged/mismatched settlement decisions
  - links issue decisions to bill, bill item, optional receipt item, product, actor, and audit timeline

The migration was executed with:

```bash
php artisan migrate
```

Result: migration completed successfully.

## Verification

Backend:

- `php -l app\Http\Controllers\API\Bills.php`: passed
- `php -l app\Services\PurchaseAccountService.php`: passed
- `php -l app\Services\PurchasingService.php`: passed
- `php -l app\Models\PurchasePaymentAllocation.php`: passed
- `php -l app\Models\PurchaseIssueResolution.php`: passed
- `php -l app\Services\ProductStockService.php`: passed
- `php -l tests\Feature\PurchasingInventoryV2Test.php`: passed
- `php artisan migrate`: passed after rerun; many pending project migrations were applied, including the new Purchasing V2 migrations
- `php artisan test --filter=PurchasingInventoryV2Test`: passed, 13 tests, 72 assertions
- `php artisan test --filter=DebtLedgerTest`: passed after Sanctum token refresh hardening, 5 tests, 16 assertions
- `php artisan test`: passed, 28 tests, 99 assertions

Flutter:

- `dart format` was run on changed Flutter files.
- `flutter analyze lib\features\admin\buying`: passed after the details tab and contextual evidence upload changes, no issues found.
- `flutter analyze test\purchase_details_model_test.dart lib\features\admin\buying`: passed, no issues found.
- `dart analyze lib\features\admin\financial_affairs\presentation\views\financial_affairs_screen.dart`: passed, no issues found.
- `flutter analyze`: completed and reported 112 existing project issues outside the Buying implementation surface; the targeted Buying/test analysis above passed cleanly.
- `flutter test test\purchase_details_model_test.dart`: attempted twice and timed out without a visible assertion failure; not counted as passed.
- `flutter build apk`: passed after the details tab refactor.
- APK output: `F:\flutter_projects\doctorbike\build\app\outputs\flutter-apk\app-release.apk`

## Known Limitations

- Contextual evidence upload is now exposed for issue resolution, Amanat actions, and payment actions. Receiving rows, purchase returns, refunds, and settlement review screens can still be deepened with richer inline attachment galleries.
- Damaged and mismatched resolution decisions now exist in backend and Flutter, including negotiated acceptance into owned stock. More detailed evidence review per issue can still be improved.
- Purchase details now use focused sections/tabs, but each section can still be evolved into richer standalone sub-screens if the product team wants an even deeper details experience.
- Full Laravel tests pass. Full Flutter analyze completes but the wider app still has pre-existing warnings/infos outside Buying. Flutter tests still time out in this local environment and should be rerun in CI or with a healthier Flutter test runner before claiming test-suite completion.

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
