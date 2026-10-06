<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Support\ProductSearchFilter;
use Tests\TestCase;

class ProductSearchFilterEnglishNameTest extends TestCase
{
    public function test_it_includes_the_english_product_name_in_authoritative_product_search(): void
    {
        $query = ProductSearchFilter::apply(Product::query(), 'bike')->toSql();

        $this->assertStringContainsString('nameEng', $query);
    }
}
