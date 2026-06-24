<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\Models\Store\StoreCategory;
use App\Models\Store\StoreProduct;
use App\Models\Store\StoreShiplyCity;
use App\Models\Store\StoreSubCategory;
use App\Models\Store\StoreUser;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class StoreBaseController extends Controller
{
    protected function rowsResponse($rows): array
    {
        $items = collect($rows)->values();

        return [
            'rows' => $items,
            'paginationInfo' => [
                'totalRowsCount' => $items->count(),
                'totalPagesCount' => 1,
            ],
        ];
    }

    protected function storeUserFromRequest(Request $request): ?StoreUser
    {
        $token = trim((string) $request->bearerToken());
        if ($token === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        return $accessToken?->tokenable instanceof StoreUser
            ? $accessToken->tokenable
            : null;
    }

    protected function cityPayload(?int $shiplyId, ?string $fallbackName = null): array
    {
        $city = $shiplyId
            ? StoreShiplyCity::query()
                ->where('shiply_id', $shiplyId)
                ->whereNull('deleted_at_remote')
                ->first()
            : null;

        $name = $city?->name ?? $fallbackName ?? '';
        $id = $city?->shiply_id ?? $shiplyId ?? 0;

        return [
            'id' => (int) $id,
            'cityNameAr' => $name,
            'cityNameEng' => $name,
            'cityNameAbree' => $name,
            'deliver' => 0.0,
            'isShow' => true,
            'userIdAdd' => null,
            'dateAdd' => $this->dateString($city?->created_at),
            'userUpdate' => null,
            'dateUpdate' => $this->dateString($city?->updated_at),
        ];
    }

    protected function userPayload(StoreUser $user): array
    {
        $cityId = is_numeric($user->city) ? (int) $user->city : null;
        $name = (string) ($user->name ?? '');
        $email = (string) ($user->email ?? '');

        return [
            'id' => (string) $user->id,
            'userName' => $email,
            'normalizedUserName' => mb_strtoupper($email),
            'email' => $email,
            'normalizedEmail' => mb_strtoupper($email),
            'emailConfirmed' => (bool) $user->email_verified_at,
            'passwordHash' => '',
            'securityStamp' => '',
            'concurrencyStamp' => '',
            'phoneNumber' => $user->phone,
            'phoneNumberConfirmed' => false,
            'twoFactorEnabled' => false,
            'lockoutEnd' => null,
            'lockoutEnabled' => false,
            'accessFailedCount' => 0,
            'address' => $user->address,
            'block' => (bool) ($user->is_blocked ?? false),
            'fullName' => $name,
            'phoneNumber2' => $user->sub_phone,
            'typeUser' => $user->type ?: 'User',
            'userToken' => $user->fcm_token ?? '',
            'dateAdd' => $this->dateString($user->created_at),
            'userUpdate' => '',
            'dateUpdate' => $this->dateString($user->updated_at),
            'cityId' => $cityId,
            'city' => $this->cityPayload($cityId, is_numeric($user->city) ? null : $user->city),
            'mainOrders' => [],
            'roles' => [],
        ];
    }

    protected function categoryPayload(StoreCategory $category): array
    {
        return [
            'id' => (int) $category->id,
            'nameAr' => (string) ($category->nameAr ?? ''),
            'nameEng' => (string) ($category->nameEng ?? $category->nameAr ?? ''),
            'nameAbree' => (string) ($category->nameAbree ?? $category->nameAr ?? ''),
            'descriptionAr' => (string) ($category->descriptionAr ?? ''),
            'descriptionEng' => (string) ($category->descriptionEng ?? ''),
            'descriptionAbree' => (string) ($category->descriptionAbree ?? ''),
            'imageUrl' => (string) ($category->imageUrl ?? ''),
            'isShow' => (bool) ($category->isShow ?? true),
            'userAdd' => (string) ($category->userAdd ?? ''),
            'dateAdd' => $this->dateString($category->dateAdd ?? $category->created_at),
            'userEdit' => (string) ($category->userEdit ?? ''),
            'dateEdit' => $this->dateString($category->dateEdit ?? $category->updated_at),
            'supCategories' => [],
        ];
    }

    protected function subCategoryPayload(StoreSubCategory $category): array
    {
        return [
            'id' => (int) $category->id,
            'nameAr' => (string) ($category->nameAr ?? ''),
            'nameEng' => (string) ($category->nameEng ?? $category->nameAr ?? ''),
            'nameAbree' => (string) ($category->nameAbree ?? $category->nameAr ?? ''),
            'descriptionAr' => (string) ($category->descriptionAr ?? ''),
            'descriptionEng' => (string) ($category->descriptionEng ?? ''),
            'descriptionAbree' => (string) ($category->descriptionAbree ?? ''),
            'imageUrl' => (string) ($category->imageUrl ?? ''),
            'isShow' => (bool) ($category->isShow ?? true),
            'mainCategoryId' => $category->mainCategoryId ? (int) $category->mainCategoryId : null,
            'userAdd' => (string) ($category->userAdd ?? ''),
            'dateAdd' => $this->dateString($category->dateAdd ?? $category->created_at),
            'userEdit' => (string) ($category->userEdit ?? ''),
            'dateEdit' => $this->dateString($category->dateEdit ?? $category->updated_at),
        ];
    }

    protected function productPayload(StoreProduct $product): array
    {
        $dateAdd = $this->dateString($product->dateAdd ?? $product->created_at);
        $dateUpdate = $this->dateString($product->dateUpdate ?? $product->updated_at);

        return [
            'id' => (int) $product->id,
            'nameAr' => (string) ($product->nameAr ?? ''),
            'nameEng' => (string) ($product->nameEng ?? $product->nameAr ?? ''),
            'nameAbree' => (string) ($product->nameAbree ?? $product->nameAr ?? ''),
            'isShow' => (bool) ($product->isShow ?? true),
            'descriptionAr' => (string) ($product->descriptionAr ?? ''),
            'descriptionEng' => (string) ($product->descriptionEng ?? ''),
            'descriptionAbree' => (string) ($product->descriptionAbree ?? ''),
            'videoUrl' => $product->videoUrl,
            'normailPrice' => (float) ($product->normailPrice ?? $product->price ?? 0),
            'wholesalePrice' => (float) ($product->wholesalePrice ?? 0),
            'stock' => (int) ($product->stock ?? 0),
            'model' => (string) ($product->model ?? ''),
            'isNewItem' => (bool) ($product->isNewItem ?? true),
            'isMoreSales' => (bool) ($product->isMoreSales ?? false),
            'rate' => (float) ($product->rate ?? 0),
            'manufactureYear' => $product->manufactureYear ? (int) $product->manufactureYear : null,
            'discount' => (float) ($product->discount ?? 0),
            'userIdAdd' => $product->userIdAdd,
            'dateAdd' => $dateAdd,
            'userIdUpdate' => $product->userIdUpdate,
            'dateUpdate' => $dateUpdate,
            'supCategory' => $product->subCategories->map(fn ($cat) => $this->subCategoryPayload($cat))->values(),
            'normalImagesItems' => $product->normalImages->map(fn ($img) => $this->imagePayload($img, $product->id))->values(),
            '_3DImagesItems' => $product->image3d->map(fn ($img) => $this->imagePayload($img, $product->id))->values(),
            'viewImagesItems' => $product->viewImages->map(fn ($img) => $this->imagePayload($img, $product->id))->values(),
            'itemSizes' => $product->sizes->map(fn ($size) => [
                'id' => $size->id ? (int) $size->id : null,
                'itemId' => $size->itemId ? (int) $size->itemId : (int) $product->id,
                'size' => (string) ($size->size ?? ''),
                'discount' => $size->discount !== null ? (float) $size->discount : null,
                'description' => (string) ($size->description ?? ''),
                'itemSizeColor' => $size->colors->map(fn ($color) => [
                    'id' => $color->id ? (int) $color->id : null,
                    'sizeId' => $color->sizeId ? (int) $color->sizeId : null,
                    'colorAr' => (string) ($color->colorAr ?? ''),
                    'colorEn' => (string) ($color->colorEn ?? ''),
                    'colorAbbr' => (string) ($color->colorAbbr ?? ''),
                    'normailPrice' => $color->normailPrice !== null ? (float) $color->normailPrice : null,
                    'wholesalePrice' => $color->wholesalePrice !== null ? (float) $color->wholesalePrice : null,
                    'discount' => $color->discount !== null ? (float) $color->discount : null,
                    'stock' => $color->stock !== null ? (int) $color->stock : null,
                ])->values(),
            ])->values(),
        ];
    }

    protected function imagePayload($image, int $productId): array
    {
        return [
            'id' => (int) $image->id,
            'imageUrl' => (string) ($image->imageUrl ?? ''),
            'itemId' => (int) ($image->itemId ?? $productId),
        ];
    }

    protected function dateString($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s');
        }

        if ($value) {
            return (string) $value;
        }

        return '1970-01-01T00:00:00';
    }
}
