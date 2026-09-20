<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\AssetLog;
use App\Models\BoxLog;
use App\Models\Category;
use App\Models\DebtTransaction;
use App\Models\EmployeeAdvanceApplication;
use App\Models\EmployeeOrder;
use App\Models\Expense;
use App\Models\Image3dProduct;
use App\Models\IncomingCheck;
use App\Models\InstantSale;
use App\Models\InventoryAdjustment;
use App\Models\NormalImageProduct;
use App\Models\OutgoingCheck;
use App\Models\Product;
use App\Models\ProfitSale;
use App\Models\ProjectExpense;
use App\Models\PurchasePayment;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\ReturnModel;
use App\Models\SalaryPaymentItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderSettlement;
use App\Models\SalesReturn;
use App\Models\SizeColor;
use App\Models\SubCategory;
use App\Models\SubCategoryProduct;
use App\Models\ViewImageProduct;
use App\Observers\AccountingProjectionObserver;
use App\Observers\MetaCatalogHierarchyObserver;
use App\Observers\ProductImageMetaCatalogObserver;
use App\Observers\ProductMetaCatalogObserver;
use App\Observers\SizeColorMetaCatalogObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Product::observe(ProductMetaCatalogObserver::class);
        SizeColor::observe(SizeColorMetaCatalogObserver::class);
        NormalImageProduct::observe(ProductImageMetaCatalogObserver::class);
        ViewImageProduct::observe(ProductImageMetaCatalogObserver::class);
        Image3dProduct::observe(ProductImageMetaCatalogObserver::class);
        Category::observe(MetaCatalogHierarchyObserver::class);
        SubCategory::observe(MetaCatalogHierarchyObserver::class);
        SubCategoryProduct::observe(MetaCatalogHierarchyObserver::class);

        foreach ([
            InstantSale::class,
            InventoryAdjustment::class,
            ProfitSale::class,
            Expense::class,
            EmployeeOrder::class,
            EmployeeAdvanceApplication::class,
            SalaryPaymentItem::class,
            SalesReturn::class,
            PurchaseReceipt::class,
            PurchaseReceiptItem::class,
            PurchasePayment::class,
            ReturnModel::class,
            Asset::class,
            AssetLog::class,
            ProjectExpense::class,
            IncomingCheck::class,
            OutgoingCheck::class,
            BoxLog::class,
            SalesOrder::class,
            SalesOrderSettlement::class,
            DebtTransaction::class,
        ] as $accountingSource) {
            $accountingSource::observe(AccountingProjectionObserver::class);
        }
    }
}
