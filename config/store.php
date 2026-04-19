<?php

// Avoid POST body loss: many hosts redirect http→https; clients often follow with GET and drop the body.
$domain = rtrim((string) env('STORE_DOMAIN', ''), '/');
if ($domain !== '' && str_starts_with($domain, 'http://')) {
    $domain = 'https://'.substr($domain, strlen('http://'));
}

return [
    'domain' => $domain,
    'email' => env('STORE_EMAIL') !== null ? trim((string) env('STORE_EMAIL')) : null,
    'password' => env('STORE_PASSWORD') !== null ? trim((string) env('STORE_PASSWORD')) : null,
    'sync_stock_on_bill' => filter_var(env('STORE_SYNC_STOCK_ON_BILL', false), FILTER_VALIDATE_BOOLEAN),
    'sync_on_product_edit' => filter_var(env('STORE_SYNC_ON_PRODUCT_EDIT', false), FILTER_VALIDATE_BOOLEAN),

    /**
     * أحجام افتراضية تظهر في قائمة الاختبار إضافةً إلى القيم المميزة من جدول sizes.
     * ليست مرتبطة بالتصنيف في Laravel — الحقل sizes.size نص حر لكل منتج (ومثل .NET ItemSize.Size).
     * يمكن تجاوزها بمتغير بيئة مفصول بفواصل: STORE_SIZE_OPTIONS="صغير,متوسط,كبير,XL"
     */
    'size_options' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('STORE_SIZE_OPTIONS', 'صغير,متوسط,كبير'))
    ))),

    /** حد أقصى لعدد المنتجات في قائمة اختيار المنتج (صفحة التعديل) */
    'product_picker_limit' => max(1, (int) env('STORE_PRODUCT_PICKER_LIMIT', 8000)),

    /**
     * مسار حذف الصنف في متجر .NET (نسبي لـ STORE_DOMAIN)، مثال: Items/DeleteItem
     * يُستدعى كـ POST مع ?itemId=
     */
    'delete_item_path' => trim((string) env('STORE_DELETE_ITEM_PATH', 'Items/DeleteItem')),

    /** جدول المنتجات التجريبي: التحقق من وجود كل صف في المتجر (GetItemById لكل صف في الصفحة) */
    'check_product_in_store_on_list' => filter_var(env('STORE_CHECK_PRODUCT_IN_STORE_ON_LIST', true), FILTER_VALIDATE_BOOLEAN),
];
