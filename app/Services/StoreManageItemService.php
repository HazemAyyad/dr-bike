<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes updated stock to the remote DoctorBike (.NET) store using ManageItem (multipart),
 * after fetching the current row via GetItemById so all required fields stay in sync.
 */
class StoreManageItemService
{
    /**
     * @param  callable|null  $step  function (string $message, array $context = []): void
     */
    public function syncProductStockToStore(Product $product, ?callable $step = null): array
    {
        $trace = $this->makeTracer($step);

        $trace('بدء المزامنة', [
            'product_id' => $product->id,
            'stock_محلي' => $product->stock,
        ]);

        if (! config('store.sync_stock_on_bill')) {
            $trace('تخطي: STORE_SYNC_STOCK_ON_BILL معطّل', []);

            return ['ok' => true, 'skipped' => true];
        }

        $base = config('store.domain');
        $email = config('store.email');
        $password = config('store.password');

        $trace('إعدادات المتجر', [
            'domain' => $base,
            'email' => $email,
            'password_set' => $password !== null && $password !== '',
        ]);

        if ($base === '' || $email === null || $password === null) {
            $trace('تخطي: إعدادات المتجر غير مكتملة', []);

            return ['ok' => true, 'skipped' => true];
        }

        $token = $this->login($base, $email, $password, $trace);
        if ($token === null) {
            $msg = 'متجر: فشل تسجيل الدخول';
            Log::warning($msg, ['product_id' => $product->id]);

            return ['ok' => false, 'error' => $msg];
        }

        $item = $this->fetchItemById($base, $token, (int) $product->id, $trace);
        if ($item === null) {
            $msg = 'متجر: تعذر جلب الصنف';
            Log::warning($msg, ['product_id' => $product->id]);

            return ['ok' => false, 'error' => $msg];
        }

        $targetStock = (int) $product->stock;
        $trace('بيانات الصنف من المتجر (قبل التعديل)', [
            'stock_في_المتجر' => $item['stock'] ?? null,
            'stock_المرسل_لـ_ManageItem' => $targetStock,
        ]);

        $form = $this->buildManageItemFormParams($item, $targetStock);
        $url = $base.'/Items/ManageItem';

        $trace('ManageItem: طلب', [
            'url' => $url,
            'fields_count' => count($form),
            'field_keys' => array_keys($form),
        ]);

        // application/x-www-form-urlencoded يطابق ربط [FromForm] في ASP.NET أفضل من multipart من Laravel هنا
        $response = Http::withToken($token)
            ->timeout(120)
            ->asForm()
            ->post($url, $form);

        $bodyPreview = mb_substr($response->body(), 0, 2000);

        if (! $response->successful()) {
            $msg = 'متجر: فشل ManageItem (HTTP '.$response->status().')';
            Log::warning($msg, [
                'product_id' => $product->id,
                'body' => $response->body(),
            ]);
            $trace('ManageItem: فشل', [
                'http_status' => $response->status(),
                'body_preview' => $bodyPreview,
            ]);

            return ['ok' => false, 'error' => $msg];
        }

        $trace('ManageItem: نجاح', [
            'http_status' => $response->status(),
            'body_preview' => $bodyPreview,
        ]);

        Log::info('متجر: تم تحديث المخزون عبر ManageItem', [
            'product_id' => $product->id,
            'stock' => $product->stock,
        ]);

        return ['ok' => true];
    }

    /**
     * بعد تعديل المنتج محلياً: يدمج بيانات Laravel مع صف المتجر ويرسل ManageItem.
     *
     * @param  callable|null  $step  function (string $message, array $context = []): void
     * @param  Request|null  $request  إن وُجدت ملفات (صور/فيديو) تُرسل multipart كـ ManageItem في .NET
     */
    public function syncProductEditToStore(Product $product, ?callable $step = null, ?Request $request = null): array
    {
        $trace = $this->makeTracer($step);

        $trace('بدء مزامنة تعديل المنتج', ['product_id' => $product->id]);

        if (! config('store.sync_on_product_edit')) {
            $trace('تخطي: STORE_SYNC_ON_PRODUCT_EDIT معطّل', []);

            return ['ok' => true, 'skipped' => true];
        }

        $base = config('store.domain');
        $email = config('store.email');
        $password = config('store.password');

        if ($base === '' || $email === null || $password === null) {
            $trace('تخطي: إعدادات المتجر غير مكتملة', []);

            return ['ok' => true, 'skipped' => true];
        }

        $token = $this->login($base, $email, $password, $trace);
        if ($token === null) {
            return ['ok' => false, 'error' => 'متجر: فشل تسجيل الدخول'];
        }

        $item = $this->fetchItemById($base, $token, (int) $product->id, $trace);
        if ($item === null) {
            return ['ok' => false, 'error' => 'متجر: تعذر جلب الصنف'];
        }

        $merged = $this->mergeStoreItemWithProduct($item, $product);
        $targetStock = $this->resolveStockForManageItem($product);

        $trace('بعد الدمج مع Laravel', [
            'stock_المرسل' => $targetStock,
            'nameAr' => $merged['nameAr'] ?? $merged['NameAr'] ?? null,
        ]);

        $form = $this->buildManageItemFormParams($merged, $targetStock);
        $url = $base.'/Items/ManageItem';

        $trace('ManageItem (تعديل): طلب', [
            'url' => $url,
            'fields_count' => count($form),
            'video_مع_ManageItem' => $request && $request->hasFile('video'),
            'صور_تُرفع_بعدها_عبر_AddImgToItem' => $request ? $this->countImageOnlyUploads($request) : 0,
        ]);

        $response = $this->postManageItem($base, $token, $form, $request, $trace);

        $bodyPreview = mb_substr($response->body(), 0, 2000);

        if (! $response->successful()) {
            Log::warning('متجر: فشل ManageItem بعد تعديل المنتج', [
                'product_id' => $product->id,
                'body' => $response->body(),
            ]);
            $trace('ManageItem: فشل', [
                'http_status' => $response->status(),
                'body_preview' => $bodyPreview,
            ]);

            return ['ok' => false, 'error' => 'متجر: فشل ManageItem (HTTP '.$response->status().')'];
        }

        $trace('ManageItem (تعديل): نجاح', ['http_status' => $response->status()]);
        Log::info('متجر: تمت مزامنة تعديل المنتج', ['product_id' => $product->id]);

        // تعديل الصنف في .NET لا يمرّر NormalImg/ViewImg/threeDImg إلى منطق الحفظ — يُرفع الفيديو فقط عبر ManageItem.
        // الصور تُضاف عبر نقطة AddImgToItem كما في لوحة المتجر.
        if ($request !== null && $this->requestHasStandaloneImageUploads($request)) {
            $imgRes = $this->pushImagesViaAddImgToItem($base, $token, $request, (int) $product->id, $trace);
            if (! ($imgRes['ok'] ?? false)) {
                return $imgRes;
            }
        }

        return ['ok' => true];
    }

    /**
     * ManageItem يستقبل multipart أساساً لـ **الفيديو** في مسار التعديل (.NET Edit يتعامل مع Video فقط).
     * الصور تُرسل لاحقاً عبر AddImgToItem.
     *
     * @param  array<string, scalar>  $form
     */
    private function postManageItem(string $base, string $token, array $form, ?Request $request, callable $trace): \Illuminate\Http\Client\Response
    {
        $url = $base.'/Items/ManageItem';

        if ($request === null || ! $request->hasFile('video')) {
            return Http::withToken($token)
                ->timeout(120)
                ->asForm()
                ->post($url, $form);
        }

        $http = Http::withToken($token)->timeout(300);
        $v = $request->file('video');
        if ($v->isValid()) {
            $http = $http->attach('Video', file_get_contents($v->getRealPath()), $v->getClientOriginalName());
        }

        $trace('ManageItem: إرسال multipart (فيديو)', []);

        return $http->post($url, $form);
    }

    private function requestHasStandaloneImageUploads(Request $request): bool
    {
        foreach (['normal_images', 'three_d_images', 'view_images'] as $key) {
            foreach ($request->file($key, []) ?: [] as $f) {
                if ($f && $f->isValid()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * يطابق POST /Items/AddImgToItem — DoctorBike.Shared.Enums.TypeImg: View, _3d, Normal
     *
     * @return array{ok: bool, error?: string}
     */
    private function pushImagesViaAddImgToItem(string $base, string $token, Request $request, int $itemId, callable $trace): array
    {
        $url = $base.'/Items/AddImgToItem';
        $map = [
            'normal_images' => 'Normal',
            'view_images' => 'View',
            'three_d_images' => '_3d',
        ];

        foreach ($map as $field => $typeImg) {
            foreach ($request->file($field, []) ?: [] as $f) {
                if (! $f || ! $f->isValid()) {
                    continue;
                }
                $path = $f->getRealPath();
                if ($path === false) {
                    $trace('AddImgToItem: ملف غير صالح', ['field' => $field, 'name' => $f->getClientOriginalName()]);

                    continue;
                }
                $trace('AddImgToItem: إرسال', [
                    'itemId' => $itemId,
                    'TypeImg' => $typeImg,
                    'name' => $f->getClientOriginalName(),
                ]);

                $response = Http::withToken($token)
                    ->timeout(120)
                    ->attach('Img', file_get_contents($path), $f->getClientOriginalName())
                    ->post($url, [
                        'ItemId' => $itemId,
                        'TypeImg' => $typeImg,
                    ]);

                if (! $response->successful()) {
                    $msg = 'متجر: فشل AddImgToItem (HTTP '.$response->status().')';
                    Log::warning($msg, [
                        'item_id' => $itemId,
                        'type' => $typeImg,
                        'body' => mb_substr($response->body(), 0, 1500),
                    ]);
                    $trace('AddImgToItem: فشل', [
                        'http_status' => $response->status(),
                        'body_preview' => mb_substr($response->body(), 0, 800),
                    ]);

                    return ['ok' => false, 'error' => $msg];
                }
            }
        }

        $trace('AddImgToItem: اكتمل رفع الصور', []);

        return ['ok' => true];
    }

    private function countImageOnlyUploads(Request $request): int
    {
        $n = 0;
        foreach (['normal_images', 'three_d_images', 'view_images'] as $key) {
            $n += count(array_filter($request->file($key, []) ?: []));
        }

        return $n;
    }

    /**
     * يدمج حقول المنتج المحلي فوق JSON GetItemById.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mergeStoreItemWithProduct(array $item, Product $product): array
    {
        $nameAr = (string) $product->nameAr;
        $merged = $item;

        $merged['nameAr'] = $nameAr;
        $merged['nameEng'] = $product->nameEng !== null && $product->nameEng !== ''
            ? (string) $product->nameEng
            : $nameAr;
        $merged['nameAbree'] = $product->nameAbree !== null && $product->nameAbree !== ''
            ? (string) $product->nameAbree
            : $nameAr;

        $merged['descriptionAr'] = (string) $product->descriptionAr;
        $merged['descriptionEng'] = $product->descriptionEng !== null
            ? (string) $product->descriptionEng
            : (string) $product->descriptionAr;
        $merged['descriptionAbree'] = $product->descriptionAbree !== null
            ? (string) $product->descriptionAbree
            : (string) $product->descriptionAr;

        $merged['isShow'] = (bool) $product->isShow;
        $merged['model'] = $product->model !== null ? (string) $product->model : '';
        $merged['isNewItem'] = (bool) $product->isNewItem;
        $merged['isMoreSales'] = (bool) $product->isMoreSales;
        $merged['rate'] = $product->rate !== null ? (float) $product->rate : 4;
        $merged['manufactureYear'] = $product->manufactureYear !== null ? (int) $product->manufactureYear : 0;
        $merged['normailPrice'] = $product->normailPrice !== null ? $product->normailPrice : 0;
        $merged['wholesalePrice'] = $product->wholesalePrice !== null ? $product->wholesalePrice : 0;
        $merged['discount'] = $product->discount !== null ? $product->discount : 0;

        $subIds = $product->subCategories
            ->pluck('sub_category_id')
            ->filter()
            ->values()
            ->all();

        if (count($subIds) > 0) {
            $merged['supCategory'] = array_map(fn (int $id) => ['id' => $id], $subIds);
        }

        if ($product->relationLoaded('sizes') && $product->sizes->isNotEmpty()) {
            $merged['itemSizes'] = $this->buildItemSizesPayloadFromProduct($product);
        }

        return $merged;
    }

    /**
     * مخزون الصنف للـ API: بدون مقاسات = product.stock؛ مع ألوان = مجموع مخزون الألوان.
     */
    private function resolveStockForManageItem(Product $product): int
    {
        if (! $product->relationLoaded('sizes')) {
            $product->load('sizes.colorSizes');
        }

        if ($product->sizes->isEmpty()) {
            return (int) $product->stock;
        }

        $sum = 0;
        foreach ($product->sizes as $size) {
            foreach ($size->colorSizes as $color) {
                $sum += (int) $color->stock;
            }
        }

        return $sum;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildItemSizesPayloadFromProduct(Product $product): array
    {
        $rows = [];
        foreach ($product->sizes as $size) {
            $colorRows = [];
            foreach ($size->colorSizes as $c) {
                $colorRows[] = [
                    'id' => (int) $c->id,
                    'sizeId' => (int) $c->sizeId,
                    'colorAr' => (string) $c->colorAr,
                    'colorEn' => $c->colorEn !== null ? (string) $c->colorEn : '',
                    'colorAbbr' => $c->colorAbbr !== null ? (string) $c->colorAbbr : '',
                    'normailPrice' => $c->normailPrice !== null ? $c->normailPrice : 0,
                    'wholesalePrice' => $c->wholesalePrice !== null ? $c->wholesalePrice : 0,
                    'discount' => $c->discount !== null ? $c->discount : 0,
                    'stock' => (int) $c->stock,
                ];
            }
            $rows[] = [
                'id' => (int) $size->id,
                'itemId' => (int) $product->id,
                'size' => (string) $size->size,
                'discount' => $size->discount !== null ? $size->discount : 0,
                'description' => $size->description !== null ? (string) $size->description : '',
                'itemSizeColor' => $colorRows,
            ];
        }

        return $rows;
    }

    private function makeTracer(?callable $step): callable
    {
        return function (string $message, array $context = []) use ($step): void {
            Log::info('[store-sync] '.$message, $context);
            if ($step !== null) {
                $step($message, $context);
            }
        };
    }

    private function login(string $base, string $email, string $password, ?callable $trace = null): ?string
    {
        $trace = $trace ?? function (): void {};
        $trace('Auth/login: إرسال الطلب', ['url' => $base.'/Auth/login']);

        $response = Http::timeout(30)
            ->acceptJson()
            ->asJson()
            ->post($base.'/Auth/login', [
                'email' => $email,
                'password' => $password,
            ]);

        if (! $response->successful()) {
            Log::warning('متجر: Auth/login فشل', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $trace('Auth/login: فشل', [
                'http_status' => $response->status(),
                'body_preview' => mb_substr($response->body(), 0, 1500),
            ]);

            return null;
        }

        $json = $response->json();
        $token = $json['token'] ?? $json['Token'] ?? null;

        $trace('Auth/login: نجاح', [
            'token_length' => $token !== null ? strlen($token) : 0,
        ]);

        return $token;
    }

    private function fetchItemById(string $base, string $token, int $itemId, ?callable $trace = null): ?array
    {
        $criteria = [
            'listRelatedObjects' => [
                'ItemSize',
                'ItemColor',
                'SupCategories',
                'ViewImgs',
                'NormalImgs',
                '_3DImgs',
            ],
            'entity' => ['nullable' => true],
            'listOrderOptions' => ['id'],
            'paginationInfo' => ['pageIndex' => 0, 'pageSize' => 0],
        ];

        $trace = $trace ?? function (): void {};
        $trace('GetItemById: إرسال الطلب', ['itemId' => $itemId, 'url' => $base.'/Items/GetItemById?itemId='.$itemId]);

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(120)
            ->post($base.'/Items/GetItemById?itemId='.$itemId, $criteria);

        if (! $response->successful()) {
            Log::warning('متجر: GetItemById فشل', [
                'item_id' => $itemId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $trace('GetItemById: فشل', [
                'http_status' => $response->status(),
                'body_preview' => mb_substr($response->body(), 0, 1500),
            ]);

            return null;
        }

        $data = $response->json();
        $trace('GetItemById: نجاح', [
            'id' => is_array($data) ? ($data['id'] ?? null) : null,
            'stock' => is_array($data) ? ($data['stock'] ?? null) : null,
            'nameAr' => is_array($data) ? ($data['nameAr'] ?? null) : null,
        ]);

        return is_array($data) ? $data : null;
    }

    /**
     * حقول مطابقة لـ ItemWriterDtos في المتجر (.NET) لربط [FromForm].
     * المفاتيح: PascalCase كما في الخصائص، والمصفوفات بصيغة supCategoriesIds[0] و ItemSizes[0].Property.
     *
     * @param  array<string, mixed>  $item  JSON من GetItemById (قد يكون camelCase أو PascalCase)
     * @return array<string, scalar>
     */
    private function buildManageItemFormParams(array $item, int $targetStock): array
    {
        $v = function (array $row, array $keys, $default = '') {
            foreach ($keys as $k) {
                if (array_key_exists($k, $row) && $row[$k] !== null) {
                    return $row[$k];
                }
            }

            return $default;
        };

        $id = (int) $v($item, ['id', 'Id'], 0);

        $form = [
            'Id' => $id,
            'NameAr' => (string) $v($item, ['nameAr', 'NameAr'], ''),
            'NameEng' => (string) $v($item, ['nameEng', 'NameEng'], ''),
            'NameAbree' => (string) $v($item, ['nameAbree', 'NameAbree'], ''),
            'IsShow' => filter_var($v($item, ['isShow', 'IsShow'], true), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'DescriptionAr' => (string) $v($item, ['descriptionAr', 'DescriptionAr'], ''),
            'DescriptionEng' => (string) $v($item, ['descriptionEng', 'DescriptionEng'], ''),
            'DescriptionAbree' => (string) $v($item, ['descriptionAbree', 'DescriptionAbree'], ''),
            'Model' => (string) $v($item, ['model', 'Model'], ''),
            'IsNewItem' => filter_var($v($item, ['isNewItem', 'IsNewItem'], true), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'IsMoreSales' => filter_var($v($item, ['isMoreSales', 'IsMoreSales'], true), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'rate' => (string) $v($item, ['rate', 'Rate'], 4),
            'ManufactureYear' => (string) (int) $v($item, ['manufactureYear', 'ManufactureYear'], 0),
            'NormailPrice' => (string) $v($item, ['normailPrice', 'NormailPrice'], 0),
            'WholesalePrice' => (string) $v($item, ['wholesalePrice', 'WholesalePrice'], 0),
            'Discount' => (string) $v($item, ['discount', 'Discount'], 0),
            'Stock' => (string) $targetStock,
        ];

        $uidAdd = $v($item, ['userIdAdd', 'UserIdAdd'], '');
        if ($uidAdd !== '' && $uidAdd !== null) {
            $form['UserIdAdd'] = (string) $uidAdd;
        }
        $uidUp = $v($item, ['userIdUpdate', 'UserIdUpdate'], '');
        if ($uidUp !== '' && $uidUp !== null) {
            $form['UserIdUpdate'] = (string) $uidUp;
        }

        $dateAdd = $v($item, ['dateAdd', 'DateAdd'], null);
        if ($dateAdd !== null && $dateAdd !== '' && ! is_array($dateAdd)) {
            $form['DateAdd'] = (string) $dateAdd;
        }
        $dateUp = $v($item, ['dateUpdate', 'DateUpdate'], null);
        if ($dateUp !== null && $dateUp !== '' && ! is_array($dateUp)) {
            $form['DateUpdate'] = (string) $dateUp;
        }

        $supCategories = $v($item, ['supCategory', 'SupCategory'], []);
        if (! is_array($supCategories)) {
            $supCategories = [];
        }
        $si = 0;
        foreach ($supCategories as $sub) {
            if (! is_array($sub)) {
                continue;
            }
            $sid = $sub['id'] ?? $sub['Id'] ?? null;
            if ($sid !== null) {
                $form['supCategoriesIds['.$si.']'] = (int) $sid;
                $si++;
            }
        }

        $sizes = $v($item, ['itemSizes', 'ItemSizes'], []);
        if (! is_array($sizes)) {
            $sizes = [];
        }
        foreach ($sizes as $sx => $size) {
            if (! is_array($size)) {
                continue;
            }
            $p = 'ItemSizes['.$sx.']';
            $form[$p.'.Id'] = (int) $v($size, ['id', 'Id'], 0);
            $form[$p.'.ItemId'] = (int) $v($size, ['itemId', 'ItemId'], $id);
            $form[$p.'.Size'] = (string) $v($size, ['size', 'Size'], '');
            $form[$p.'.Discount'] = (string) $v($size, ['discount', 'Discount'], 0);
            $form[$p.'.Description'] = (string) $v($size, ['description', 'Description'], '');

            $colors = $v($size, ['itemSizeColor', 'ItemSizeColor'], []);
            if (! is_array($colors)) {
                $colors = [];
            }
            foreach ($colors as $ci => $color) {
                if (! is_array($color)) {
                    continue;
                }
                $cp = $p.'.ItemSizeColor['.$ci.']';
                $form[$cp.'.Id'] = (int) $v($color, ['id', 'Id'], 0);
                $form[$cp.'.SizeId'] = (int) $v($color, ['sizeId', 'SizeId'], (int) $v($size, ['id', 'Id'], 0));
                $form[$cp.'.ColorAr'] = (string) $v($color, ['colorAr', 'ColorAr'], '');
                $form[$cp.'.ColorEn'] = (string) $v($color, ['colorEn', 'ColorEn'], '');
                $form[$cp.'.ColorAbbr'] = (string) $v($color, ['colorAbbr', 'ColorAbbr'], '');
                $form[$cp.'.NormailPrice'] = (string) $v($color, ['normailPrice', 'NormailPrice'], 0);
                $form[$cp.'.WholesalePrice'] = (string) $v($color, ['wholesalePrice', 'WholesalePrice'], 0);
                $form[$cp.'.Discount'] = (string) $v($color, ['discount', 'Discount'], 0);
                $form[$cp.'.Stock'] = (string) $v($color, ['stock', 'Stock'], 0);
            }
        }

        return $form;
    }
}
