<?php

namespace Tests\Support;

use App\Models\Customer;
use App\Models\DebtTransaction;
use App\Models\Product;
use App\Models\Seller;
use App\Models\Size;
use App\Models\SizeColor;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

final class OnlineStoreFixtureFactory
{
    private static int $numericSequence = 0;

    public static function createAdminActor(array $overrides = []): User
    {
        return self::createActor('admin', $overrides);
    }

    public static function createStoreActor(array $overrides = []): User
    {
        return self::createActor('User', $overrides);
    }

    public static function createAuthenticatedAdminActor(array $overrides = [], array $abilities = ['*']): User
    {
        return self::authenticate(self::createAdminActor($overrides), $abilities);
    }

    public static function createAuthenticatedStoreActor(array $overrides = [], array $abilities = ['*']): User
    {
        return self::authenticate(self::createStoreActor($overrides), $abilities);
    }

    public static function createCustomer(array $overrides = []): Customer
    {
        self::guard();

        return Customer::query()->forceCreate(array_merge([
            'name' => 'Fixture Customer',
            'type' => 'customer',
            'phone' => null,
            'address' => null,
            'is_canceled' => false,
        ], $overrides));
    }

    public static function createSeller(array $overrides = []): Seller
    {
        self::guard();

        return Seller::query()->forceCreate(array_merge([
            'name' => 'Fixture Supplier',
            'type' => 'seller',
            'phone' => null,
            'address' => null,
            'is_canceled' => false,
        ], $overrides));
    }

    public static function createProduct(array $overrides = []): Product
    {
        self::guard();
        $id = self::nextNumericId();

        return Product::query()->forceCreate(array_merge([
            'id' => $id,
            'product_code' => str_pad((string) ($id % 1_000_000), 6, '0', STR_PAD_LEFT),
            'nameAr' => 'Fixture Product',
            'nameEng' => 'Fixture Product',
            'nameAbree' => 'Fixture Product',
            'isShow' => true,
            'normailPrice' => 100,
            'wholesalePrice' => 80,
            'stock' => 5,
        ], $overrides));
    }

    /** @return array{size: Size, variant: SizeColor} */
    public static function createVariant(Product $product, array $sizeOverrides = [], array $variantOverrides = []): array
    {
        self::guard();
        $sizeId = self::nextNumericId();
        $variantId = self::nextNumericId();

        $size = Size::query()->forceCreate(array_merge([
            'id' => $sizeId,
            'itemId' => $product->getKey(),
            'size' => 'fixture-size',
            'discount' => 0,
        ], $sizeOverrides));

        $variant = SizeColor::query()->forceCreate(array_merge([
            'id' => $variantId,
            'sizeId' => $size->getKey(),
            'colorAr' => 'fixture-color',
            'colorEn' => 'fixture-color',
            'colorAbbr' => 'fixture',
            'normailPrice' => $product->normailPrice ?? 100,
            'wholesalePrice' => $product->wholesalePrice ?? 80,
            'discount' => 0,
            'stock' => 5,
        ], $variantOverrides));

        return compact('size', 'variant');
    }

    public static function setStock(Product $product, int $quantity, ?SizeColor $variant = null): Product|SizeColor
    {
        self::guard();
        $stockOwner = $variant ?? $product;
        $stockOwner->forceFill(['stock' => $quantity])->save();

        return $stockOwner->fresh();
    }

    public static function createCustomerLedgerEntry(Customer $customer, array $overrides = []): DebtTransaction
    {
        return self::createLedgerEntry(['customer_id' => $customer->getKey(), 'seller_id' => null], $overrides);
    }

    public static function createSellerLedgerEntry(Seller $seller, array $overrides = []): DebtTransaction
    {
        return self::createLedgerEntry(['customer_id' => null, 'seller_id' => $seller->getKey()], $overrides);
    }

    private static function createActor(string $type, array $overrides): User
    {
        self::guard();
        $token = (string) Str::uuid();

        return User::query()->forceCreate(array_merge([
            'name' => $type === 'admin' ? 'Fixture Admin' : 'Fixture Store Actor',
            'email' => "fixture-{$token}@example.invalid",
            'password' => Hash::make(Str::random(40)),
            'type' => $type,
            'phone' => null,
            'is_blocked' => false,
        ], $overrides));
    }

    private static function authenticate(User $actor, array $abilities): User
    {
        Sanctum::actingAs($actor, $abilities);

        return $actor;
    }

    private static function createLedgerEntry(array $party, array $overrides): DebtTransaction
    {
        self::guard();

        return DebtTransaction::query()->forceCreate(array_merge([
            ...$party,
            'type' => 'taken',
            'amount' => 100,
            'currency' => 'ILS',
            'balance_after' => 100,
            'note' => 'Synthetic Online Store test ledger entry',
            'transaction_date' => now()->toDateString(),
            'source' => 'online_store_test_fixture',
            'source_id' => null,
            'created_by' => null,
        ], Arr::except($overrides, $party['customer_id'] === null ? ['customer_id'] : ['seller_id'])));
    }

    private static function nextNumericId(): int
    {
        if (self::$numericSequence === 0) {
            self::$numericSequence = random_int(1_500_000_000, 1_900_000_000);
        }

        return self::$numericSequence++;
    }

    private static function guard(): void
    {
        RequiresDisposableDatabase::assertDisposableDatabase();
    }
}
