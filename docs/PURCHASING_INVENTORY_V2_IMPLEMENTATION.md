# Purchasing Inventory V2 Implementation Report

Date: 2026-08-19

## Baseline And Rollback Points

- Backend repository: `F:\laragon\www\doctor-bike`
- Backend baseline branch: `main`
- Backend baseline SHA: `1d9146b4ec9c211f4e7676ae7db00fbf7d97dfca`
- Backend implementation branch: `codex/purchasing-inventory-v2-20260819-1110`
- Backend baseline tag: `backup/pre-purchasing-inventory-v2-20260819-1110`
- Flutter repository: `F:\flutter_projects\doctorbike`
- Flutter baseline branch: `main`
- Flutter baseline SHA: `02b161ec132cdae0e3c38ea76d6c113cb4139076`
- Flutter implementation branch: `codex/purchasing-inventory-v2-20260819-1110`
- Flutter baseline tag: `backup/pre-purchasing-inventory-v2-20260819-1110`

## Backups

- SQL dump: `C:\Users\hp\AppData\Local\Temp\doctor-bike-purchasing-inventory-v2-20260819-1110\db-dr-bike-new-pre-purchasing-inventory-v2-20260819-1110.sql`
- Dump size verified: `47489691` bytes.
- Flutter dirty-work backup: `C:\Users\hp\AppData\Local\Temp\doctor-bike-purchasing-inventory-v2-20260819-1110\flutter\dirty.patch`
- Pre-existing Flutter dirty change: `pubspec.yaml` version changed from `1.0.0+42` to `1.0.0+43`.

## Commits Created

Backend:

- `d94405d32dc0f758b3eba77646e7eb1f48aa4dd7` - Phase 01 - purchasing inventory foundation
- `e068247582ae08b664d99ff57f3077530c72c202` - Phase 02 - expose purchase receiving details

Flutter:

- `a78ae30` - Phase 09 - wire purchasing workflow UI

## Backend Changes

- Added additive purchasing foundation migration:
  - `purchase_receipts`
  - `purchase_receipt_items`
  - `purchase_amanat_stocks`
  - `purchase_price_histories`
  - `purchase_payments`
  - `purchase_attachments`
  - `purchase_activity_logs`
  - `inventory_cost_layers`
  - `inventory_cost_allocations`
- Extended `bills` and `bill_items` with workflow/payment/receiving compatibility fields.
- Added compatibility guards for older local schema variants:
  - `permissions.name_en`
  - `boxes.currency`
  - `sizes.itemId`
  - legacy `bill_items` status/price discrepancy fields when missing.
- Added purchasing services:
  - `PurchasingService`
  - `InventoryCostingService`
  - `PurchaseActivityService`
- Updated purchase creation so it no longer increases stock immediately.
- Added receiving flow that increases owned stock only for accepted quantities.
- Added amanat/custody stock for extra delivered quantities.
- Added purchase of amanat at negotiated price.
- Added immutable purchase price history.
- Added inventory cost layers and FIFO / moving average cost allocation.
- Integrated purchase finalization and payments with `DebtLedgerService` and `Box` movement.
- Added purchase source labels to debt ledger.
- Added purchase workflow API endpoints under the existing purchasing permission group.

## Flutter Changes

- Added endpoints for receiving/finalization/payment/amanat purchase.
- Added datasource/repository/usecase methods for purchase workflow actions.
- Extended bill details model with workflow/payment/receiving quantities.
- Added purchase workflow panel on bill details screen:
  - receiving status
  - payment status
  - ordered vs received quantities
  - final/paid/remaining totals
  - action to receive remaining shown quantities
  - action to finalize without an initial payment

## Verification

Backend:

- `php -l app\Services\PurchasingService.php` passed.
- `php -l app\Services\InventoryCostingService.php` passed.
- `php -l app\Http\Controllers\API\Bills.php` passed.
- `php artisan migrate` passed.
- `php artisan test --filter=PurchasingInventoryV2Test` passed: 4 tests, 20 assertions.

Flutter:

- `dart format` run on changed Flutter files.
- `flutter analyze lib\features\admin\buying` passed with no issues.

## Important Notes

- During early testing, `RefreshDatabase` was unsafe because `phpunit.xml` uses the local MySQL database rather than isolated SQLite. A pre-implementation SQL dump exists and migrations were reapplied with `php artisan migrate`.
- Tests were changed to `DatabaseTransactions` to avoid `migrate:fresh`.
- No GitHub push was performed.
- No completion tag was created because the full requested 48-section implementation is not fully complete.

## Current Limitations

- Flutter payment UI has backend/repository/controller support, but the visible bill details action currently finalizes without an initial payment. A full box selector payment dialog still needs to be added before exposing direct payment buttons.
- Purchase returns, supplier refunds, allocation of account payments, attachment upload UI, full audit timeline UI, settings UI for costing method, and full sales/maintenance costing integration are not complete.
- Full backend test suite and full Flutter test suite were not completed in this pass.

## Rollback

Code-only rollback for local/unshared history:

```bash
git switch main
git reset --hard backup/pre-purchasing-inventory-v2-20260819-1110
```

For shared history, revert commits in reverse order instead of rewriting history:

```bash
git revert e068247582ae08b664d99ff57f3077530c72c202
git revert d94405d32dc0f758b3eba77646e7eb1f48aa4dd7
```

Flutter shared-history rollback:

```bash
git revert a78ae30
```

Full rollback including database:

1. Stop application writes.
2. Restore backend and Flutter code to the baseline tags.
3. Restore SQL dump from `C:\Users\hp\AppData\Local\Temp\doctor-bike-purchasing-inventory-v2-20260819-1110\db-dr-bike-new-pre-purchasing-inventory-v2-20260819-1110.sql`.
4. Run `php artisan config:clear`.
5. Run `php artisan migrate:status` and targeted smoke tests.
