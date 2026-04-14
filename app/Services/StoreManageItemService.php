<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes updated stock to the remote DoctorBike (.NET) store using ManageItem (multipart),
 * after fetching the current row via GetItemById so all required fields stay in sync.
 */
class StoreManageItemService
{
    public function syncProductStockToStore(Product $product): array
    {
        if (! config('store.sync_stock_on_bill')) {
            return ['ok' => true];
        }

        $base = config('store.domain');
        $email = config('store.email');
        $password = config('store.password');

        if ($base === '' || $email === null || $password === null) {
            return ['ok' => true];
        }

        $token = $this->login($base, $email, $password);
        if ($token === null) {
            $msg = 'متجر: فشل تسجيل الدخول';
            Log::warning($msg, ['product_id' => $product->id]);

            return ['ok' => false, 'error' => $msg];
        }

        $item = $this->fetchItemById($base, $token, (int) $product->id);
        if ($item === null) {
            $msg = 'متجر: تعذر جلب الصنف';
            Log::warning($msg, ['product_id' => $product->id]);

            return ['ok' => false, 'error' => $msg];
        }

        $parts = $this->buildManageItemMultipart($item, (int) $product->stock);
        $url = $base.'/Items/ManageItem';

        $response = Http::withToken($token)
            ->timeout(120)
            ->asMultipart($parts)
            ->post($url);

        if (! $response->successful()) {
            $msg = 'متجر: فشل ManageItem (HTTP '.$response->status().')';
            Log::warning($msg, [
                'product_id' => $product->id,
                'body' => $response->body(),
            ]);

            return ['ok' => false, 'error' => $msg];
        }

        return ['ok' => true];
    }

    private function login(string $base, string $email, string $password): ?string
    {
        $response = Http::timeout(30)->post($base.'/Auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('token');
    }

    private function fetchItemById(string $base, string $token, int $itemId): ?array
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

        $response = Http::withToken($token)
            ->timeout(120)
            ->post($base.'/Items/GetItemById?itemId='.$itemId, $criteria);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    /**
     * Build multipart parts for ItemWriterDtos / ManageItem. Only Stock is replaced with $targetStock.
     *
     * @param  array<string, mixed>  $item  JSON from GetItemById (camelCase)
     * @return array<int, array{name: string, contents: string}>
     */
    private function buildManageItemMultipart(array $item, int $targetStock): array
    {
        $parts = [];

        $add = function (string $name, $value) use (&$parts): void {
            if ($value === null) {
                return;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $parts[] = ['name' => $name, 'contents' => (string) $value];
        };

        $id = (int) ($item['id'] ?? 0);
        $add('Id', $id);

        $add('NameAr', $item['nameAr'] ?? '');
        $add('NameEng', $item['nameEng'] ?? '');
        $add('NameAbree', $item['nameAbree'] ?? '');

        $add('IsShow', (bool) ($item['isShow'] ?? true));
        $add('DescriptionAr', $item['descriptionAr'] ?? '');
        $add('DescriptionEng', $item['descriptionEng'] ?? '');
        $add('DescriptionAbree', $item['descriptionAbree'] ?? '');
        $add('Model', $item['model'] ?? '');

        $add('IsNewItem', $item['isNewItem'] ?? true);
        $add('IsMoreSales', $item['isMoreSales'] ?? true);

        $add('rate', $item['rate'] ?? 4);
        $add('ManufactureYear', $item['manufactureYear'] ?? 0);

        $add('NormailPrice', $item['normailPrice'] ?? 0);
        $add('WholesalePrice', $item['wholesalePrice'] ?? 0);
        $add('Discount', $item['discount'] ?? 0);

        $add('Stock', $targetStock);

        $add('UserIdAdd', $item['userIdAdd'] ?? '');
        $this->addDatePart($add, 'DateAdd', $item['dateAdd'] ?? null);
        $add('UserIdUpdate', $item['userIdUpdate'] ?? '');
        $this->addDatePart($add, 'DateUpdate', $item['dateUpdate'] ?? null);

        $supCategories = $item['supCategory'] ?? [];
        if (! is_array($supCategories)) {
            $supCategories = [];
        }
        $i = 0;
        foreach ($supCategories as $sub) {
            if (! is_array($sub)) {
                continue;
            }
            if (isset($sub['id'])) {
                $parts[] = ['name' => 'supCategoriesIds['.$i.']', 'contents' => (string) $sub['id']];
                $i++;
            }
        }

        $sizes = $item['itemSizes'] ?? [];
        if (is_array($sizes)) {
            foreach ($sizes as $si => $size) {
                if (! is_array($size)) {
                    continue;
                }
                $p = 'ItemSizes['.$si.']';
                $add($p.'.Id', $size['id'] ?? 0);
                $add($p.'.ItemId', $size['itemId'] ?? $id);
                $add($p.'.Size', $size['size'] ?? '');
                $add($p.'.Discount', $size['discount'] ?? 0);
                $add($p.'.Description', $size['description'] ?? '');

                $colors = $size['itemSizeColor'] ?? [];
                if (! is_array($colors)) {
                    $colors = [];
                }
                foreach ($colors as $ci => $color) {
                    if (! is_array($color)) {
                        continue;
                    }
                    $cp = $p.'.ItemSizeColor['.$ci.']';
                    $add($cp.'.Id', $color['id'] ?? 0);
                    $add($cp.'.SizeId', $color['sizeId'] ?? ($size['id'] ?? 0));
                    $add($cp.'.ColorAr', $color['colorAr'] ?? '');
                    $add($cp.'.ColorEn', $color['colorEn'] ?? '');
                    $add($cp.'.ColorAbbr', $color['colorAbbr'] ?? '');
                    $add($cp.'.NormailPrice', $color['normailPrice'] ?? 0);
                    $add($cp.'.WholesalePrice', $color['wholesalePrice'] ?? 0);
                    $add($cp.'.Discount', $color['discount'] ?? 0);
                    $add($cp.'.Stock', $color['stock'] ?? 0);
                }
            }
        }

        return $parts;
    }

    private function addDatePart(callable $add, string $name, $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (is_array($value)) {
            return;
        }
        $add($name, $value);
    }
}
