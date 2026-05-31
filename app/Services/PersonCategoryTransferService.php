<?php

namespace App\Services;

use App\Models\ContactCategoryAssignment;
use App\Models\Customer;
use App\Models\Seller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PersonCategoryTransferService
{
    /**
     * نقل زبون (مفرق) إلى تاجر (جملة) مع تحديث كل المراجع.
     *
     * @param  array<string, mixed>  $updates
     */
    public function transferCustomerToSeller(Customer $customer, array $updates): Seller
    {
        return DB::transaction(function () use ($customer, $updates) {
            $payload = $this->buildPayload($customer->toArray(), $updates, 'wholesale');

            $seller = $this->findOrCreateSeller($payload);
            $seller->update($payload);

            $this->rewireCustomerReferences((int) $customer->id, (int) $seller->id);
            $this->migrateContactCategories((int) $customer->id, (int) $seller->id);

            $customer->update(['is_canceled' => 1]);

            return $seller->fresh();
        });
    }

    /**
     * نقل تاجر (جملة) إلى زبون (مفرق) مع تحديث كل المراجع.
     *
     * @param  array<string, mixed>  $updates
     */
    public function transferSellerToCustomer(Seller $seller, array $updates): Customer
    {
        return DB::transaction(function () use ($seller, $updates) {
            $payload = $this->buildPayload($seller->toArray(), $updates, 'retail');

            $customer = $this->findOrCreateCustomer($payload);
            $customer->update($payload);

            $this->rewireSellerReferences((int) $seller->id, (int) $customer->id);
            $this->migrateContactCategoriesToCustomer((int) $seller->id, (int) $customer->id);

            $seller->update(['is_canceled' => 1]);

            return $customer->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function buildPayload(array $existing, array $updates, string $type): array
    {
        $fields = [
            'name', 'phone', 'sub_phone', 'address', 'job_title', 'facebook_username',
            'facebook_link', 'instagram_username', 'instagram_link', 'related_people',
            'work_address', 'relative_phone', 'relative_job_title', 'ID_image', 'license_image',
            'notes', 'collection_reminder_at',
        ];

        $payload = ['type' => $type];
        foreach ($fields as $field) {
            if (array_key_exists($field, $updates)) {
                $payload[$field] = $updates[$field];
            } elseif (array_key_exists($field, $existing)) {
                $payload[$field] = $existing[$field];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findOrCreateSeller(array $payload): Seller
    {
        if (! empty($payload['phone'])) {
            $existing = Seller::query()
                ->where('phone', $payload['phone'])
                ->where(function ($q) {
                    $q->whereNull('is_canceled')->orWhere('is_canceled', 0);
                })
                ->first();

            if ($existing instanceof Seller) {
                return $existing;
            }
        }

        return Seller::create($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findOrCreateCustomer(array $payload): Customer
    {
        if (! empty($payload['phone'])) {
            $existing = Customer::query()
                ->where('phone', $payload['phone'])
                ->where(function ($q) {
                    $q->whereNull('is_canceled')->orWhere('is_canceled', 0);
                })
                ->first();

            if ($existing instanceof Customer) {
                return $existing;
            }
        }

        return Customer::create($payload);
    }

    private function rewireCustomerReferences(int $customerId, int $sellerId): void
    {
        $this->moveColumn('debt_transactions', 'customer_id', $customerId, 'seller_id', $sellerId);
        $this->moveColumn('debts', 'customer_id', $customerId, 'seller_id', $sellerId);
        $this->moveColumn('outgoing_checks', 'customer_id', $customerId, 'seller_id', $sellerId);
        $this->moveColumn('debt_ledger_activity_logs', 'customer_id', $customerId, 'seller_id', $sellerId);
        $this->moveColumn('instant_sales', 'buyer_id', $customerId, 'seller_id', $sellerId);

        $this->moveColumn('incoming_checks', 'from_customer', $customerId, 'from_seller', $sellerId);
        $this->moveColumn('incoming_checks', 'to_customer', $customerId, 'to_seller', $sellerId);
    }

    private function rewireSellerReferences(int $sellerId, int $customerId): void
    {
        $this->moveColumn('debt_transactions', 'seller_id', $sellerId, 'customer_id', $customerId);
        $this->moveColumn('debts', 'seller_id', $sellerId, 'customer_id', $customerId);
        $this->moveColumn('outgoing_checks', 'seller_id', $sellerId, 'customer_id', $customerId);
        $this->moveColumn('debt_ledger_activity_logs', 'seller_id', $sellerId, 'customer_id', $customerId);
        $this->moveColumn('instant_sales', 'seller_id', $sellerId, 'buyer_id', $customerId);

        $this->moveColumn('incoming_checks', 'from_seller', $sellerId, 'from_customer', $customerId);
        $this->moveColumn('incoming_checks', 'to_seller', $sellerId, 'to_customer', $customerId);
    }

    private function moveColumn(
        string $table,
        string $fromColumn,
        int $fromId,
        string $toColumn,
        int $toId
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $fromColumn)) {
            return;
        }

        $query = DB::table($table)->where($fromColumn, $fromId);

        if (Schema::hasColumn($table, $toColumn)) {
            $query->update([
                $fromColumn => null,
                $toColumn => $toId,
            ]);

            return;
        }

        $query->update([$fromColumn => $toId]);
    }

    private function migrateContactCategories(int $customerId, int $sellerId): void
    {
        if (! Schema::hasTable('contact_category_assignments')) {
            return;
        }

        $categoryIds = ContactCategoryAssignment::query()
            ->where('customer_id', $customerId)
            ->pluck('contact_category_id');

        ContactCategoryAssignment::query()->where('customer_id', $customerId)->delete();

        foreach ($categoryIds as $categoryId) {
            ContactCategoryAssignment::query()->firstOrCreate([
                'contact_category_id' => $categoryId,
                'seller_id' => $sellerId,
            ], [
                'customer_id' => null,
            ]);
        }
    }

    private function migrateContactCategoriesToCustomer(int $sellerId, int $customerId): void
    {
        if (! Schema::hasTable('contact_category_assignments')) {
            return;
        }

        $categoryIds = ContactCategoryAssignment::query()
            ->where('seller_id', $sellerId)
            ->pluck('contact_category_id');

        ContactCategoryAssignment::query()->where('seller_id', $sellerId)->delete();

        foreach ($categoryIds as $categoryId) {
            ContactCategoryAssignment::query()->firstOrCreate([
                'contact_category_id' => $categoryId,
                'customer_id' => $customerId,
            ], [
                'seller_id' => null,
            ]);
        }
    }
}
