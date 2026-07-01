<?php

namespace App\Providers;

use App\Models\Product;
use App\Models\SizeColor;
use App\Models\NormalImageProduct;
use App\Models\ViewImageProduct;
use App\Models\Image3dProduct;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\SubCategoryProduct;
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
    }
}
