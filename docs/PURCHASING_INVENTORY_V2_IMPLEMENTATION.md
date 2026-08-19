# Purchasing Inventory V2 Implementation Report

Date: 2026-08-19

## Baseline And Rollback Points

- Backend repository: `F:\laragon\www\doctor-bike`
- Backend baseline branch: `main`
- Backend baseline SHA: `1d9146b4ec9c211f4e7676ae7db00fbf7d97dfca`
- Backend implementation branch: `purchasing-inventory-v2-20260819-1110`
- Backend baseline tag: `backup/pre-purchasing-inventory-v2-20260819-1110`
- Flutter repository: `F:\flutter_projects\doctorbike`
- Flutter baseline branch: `main`
- Flutter baseline SHA: `02b161ec132cdae0e3c38ea76d6c113cb4139076`
- Flutter implementation branch: `purchasing-inventory-v2-20260819-1110`
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
- `38c8fa0` - Phase 06 - expose inventory costing setting
- `a516008` - Phase 05 - add purchase returns and account payments
- `2a0df6d` - Phase 07 - integrate outbound inventory costing
- `bfa6040` - Phase 12 - document purchasing inventory rollout
- `c2d7188` - Phase 12 - update purchasing inventory verification report
- `7e42056` - Phase 08 - expose purchase attachments and custody details

Flutter:

- `a78ae30` - Phase 09 - wire purchasing workflow UI
- `aa5029c` - Phase 10 - add purchase payment controls
- `3bcf313` - Phase 10 - add inventory costing settings UI
- `8806a8f` - Phase 10 - add purchase account timeline controls
- `aa68316` - Phase 10 - complete purchase custody attachments UI

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
- Added inventory costing setting through `AppSettingsController`:
  - `inventory_costing_method`
  - `inventory_costing_method_effective_from`
- Added purchase returns and supplier-account operations:
  - `PurchaseAccountService`
  - amanat return without entering owned inventory
  - supplier account payment with oldest-invoice allocation
  - purchase return as supplier credit or cash refund
  - purchase return stock/cost-layer consumption
- Added purchase timeline endpoint.
- Added purchase attachment upload endpoint:
  - `POST /purchase/attachments`
  - stores evidence on the public disk under `purchase-evidence/{bill_id}`
  - supports optional `attachable_type` / `attachable_id` links
- Extended purchase bill details with:
  - `customer_id`
  - payments
  - returns and return items
  - attachments with public URLs
  - compact timeline
  - per-product amanat stock rows with `amanat_id`, remaining quantity, status, negotiated price, and notes
- Added outbound cost snapshots for instant sales and maintenance consumption:
  - `instant_sales.inventory_cost_method`
  - `instant_sales.inventory_unit_cost`
  - `instant_sales.inventory_total_cost`
  - `maintenance_products.inventory_cost_method`
  - `maintenance_products.inventory_unit_cost`
  - `maintenance_products.inventory_total_cost`
- Added idempotent backfill command:
  - `php artisan inventory:backfill-cost-layers`
  - `php artisan inventory:backfill-cost-layers --write`
- Backfill write report: `storage/app/inventory-cost-backfill/backfill-20260819-203215.json`
- Backfill result on current local DB: created opening layers `0`, products needing review `0`.

## Flutter Changes

- Added endpoints for receiving/finalization/payment/amanat purchase.
- Added endpoints for supplier account payment, amanat return, purchase timeline, and purchase attachments.
- Added datasource/repository/usecase methods for purchase workflow actions.
- Extended bill details model with workflow/payment/receiving quantities, amanat rows, payments, returns, attachments, and timeline.
- Added purchase workflow panel on bill details screen:
  - receiving status
  - payment status
  - ordered vs received quantities
  - final/paid/remaining totals
  - action to receive remaining shown quantities
  - action to finalize with optional initial payment and box selection
  - action to record later invoice payment
  - action to record supplier account payment and allocate oldest invoices first
  - action to purchase or return individual amanat rows
  - action to upload purchase evidence files
  - attachment list with external open support
  - compact payment and return summaries
  - compact purchase activity timeline preview
- Added inventory costing method UI under stock inventory settings:
  - FIFO
  - Moving Weighted Average
  - Arabic warning that the change applies prospectively only

## Verification

Backend:

- `php -l app\Services\PurchasingService.php` passed.
- `php -l app\Services\InventoryCostingService.php` passed.
- `php -l app\Http\Controllers\API\Bills.php` passed.
- `php -l app\Http\Controllers\API\ReturnsAPI.php` passed.
- `php -l app\Services\PurchaseAccountService.php` passed.
- `php -l app\Services\PurchaseAttachmentService.php` passed.
- `php -l app\Services\ProductStockService.php` passed.
- `php -l app\Console\Commands\BackfillInventoryCostLayers.php` passed.
- `php artisan migrate` passed.
- `php artisan migrate:status` shows new migrations as Ran:
  - `2026_08_19_111000_purchase_inventory_v2_foundation`
  - `2026_08_19_112000_purchase_returns_account_payments_and_audit`
  - `2026_08_19_113000_inventory_cost_snapshots_and_backfill_support`
- `php artisan inventory:backfill-cost-layers` dry-run passed: would create `0`, review `0`.
- `php artisan inventory:backfill-cost-layers --write` passed: created `0`, review `0`.
- `php artisan test --filter=PurchasingInventoryV2Test` passed: 9 tests, 42 assertions.

Flutter:

- `dart format` run on changed Flutter files.
- `flutter analyze lib\features\admin\buying` passed with no issues.
- `flutter analyze lib\features\admin\buying lib\features\admin\general_settings\presentation\views\stock_inventory_settings_screen.dart lib\core\services\app_settings_service.dart` passed with no issues.

## Important Notes

- During early testing, `RefreshDatabase` was unsafe because `phpunit.xml` uses the local MySQL database rather than isolated SQLite. A pre-implementation SQL dump exists and migrations were reapplied with `php artisan migrate`.
- Tests were changed to `DatabaseTransactions` to avoid `migrate:fresh`.
- No GitHub push was performed.
- No completion tag was created yet because full-suite verification and production builds were not run.

## Current Limitations

- Timeline backend exists and Flutter shows a compact preview. A full detailed audit screen with filters is not yet added.
- Outbound costing is integrated into central stock deduction when layers exist. Existing old stock is protected by the backfill command; if future legacy data lacks defensible cost, it will be flagged for review rather than blocking sales.
- Full `php artisan test`, `flutter test`, and production build were not run in this pass; targeted Laravel tests and Flutter analyzer passed.

## Rollback

Code-only rollback for local/unshared history:

```bash
git switch main
git reset --hard backup/pre-purchasing-inventory-v2-20260819-1110
```

For shared history, revert commits in reverse order instead of rewriting history:

```bash
git revert 7e42056
git revert c2d7188
git revert 2a0df6d
git revert a516008
git revert 38c8fa0
git revert bfa6040
git revert e068247582ae08b664d99ff57f3077530c72c202
git revert d94405d32dc0f758b3eba77646e7eb1f48aa4dd7
```

Flutter shared-history rollback:

```bash
git revert aa68316
git revert 8806a8f
git revert 3bcf313
git revert aa5029c
git revert a78ae30
```

Full rollback including database:

1. Stop application writes.
2. Restore backend and Flutter code to the baseline tags.
3. Restore SQL dump from `C:\Users\hp\AppData\Local\Temp\doctor-bike-purchasing-inventory-v2-20260819-1110\db-dr-bike-new-pre-purchasing-inventory-v2-20260819-1110.sql`.
4. Run `php artisan config:clear`.
5. Run `php artisan migrate:status` and targeted smoke tests.
