<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OnlineStoreSettingsService
{
    public const LANGUAGES = ['ar', 'en', 'he'];

    public function current(bool $create = true): ?OnlineStoreSettings
    {
        $settings = OnlineStoreSettings::query()->find(OnlineStoreSettings::SINGLETON_ID);
        if ($settings || ! $create) {
            return $settings;
        }

        return DB::transaction(function () {
            $settings = OnlineStoreSettings::query()->lockForUpdate()->find(OnlineStoreSettings::SINGLETON_ID);
            if ($settings) {
                return $settings;
            }

            $settings = new OnlineStoreSettings;
            $settings->forceFill($this->defaults());
            $settings->save();
            app(OnlineStoreAuditService::class)->record(null, 'created', 'settings', (int) $settings->id, null, $settings->getAttributes());

            return $settings;
        });
    }

    public function save(User $actor, array $data): OnlineStoreSettings
    {
        return DB::transaction(function () use ($actor, $data) {
            $settings = OnlineStoreSettings::query()->lockForUpdate()->find(OnlineStoreSettings::SINGLETON_ID);
            if (! $settings) {
                $settings = new OnlineStoreSettings($this->defaults());
                $settings->id = OnlineStoreSettings::SINGLETON_ID;
            }
            $before = $settings->exists ? $settings->getAttributes() : null;
            $candidate = array_merge($settings->attributesToArray(), $data);
            $this->validate($candidate);
            $settings->fill($data);
            $settings->out_of_stock_behavior = OnlineStoreSettings::OUT_OF_STOCK_BEHAVIOR;
            $settings->updated_by = $actor->getKey();
            $settings->save();
            app(OnlineStoreAuditService::class)->record($actor, $before ? 'updated' : 'created', 'settings', (int) $settings->id, $before, $settings->getAttributes());

            return $settings->fresh();
        });
    }

    public function operatingState(?OnlineStoreSettings $settings = null, bool $authenticated = false): array
    {
        $settings ??= $this->current();
        if (! $settings->store_enabled) {
            return ['state' => 'disabled', 'browsing_allowed' => false, 'checkout_allowed' => false, 'cod_allowed' => false];
        }
        if ($settings->maintenance_mode) {
            return ['state' => 'maintenance', 'browsing_allowed' => false, 'checkout_allowed' => false, 'cod_allowed' => false];
        }

        $checkout = (bool) $settings->checkout_enabled;

        return [
            'state' => $checkout ? 'open' : 'browse_only',
            'browsing_allowed' => $authenticated || (bool) $settings->guest_browsing_enabled,
            'checkout_allowed' => $checkout,
            'cod_allowed' => $checkout && (bool) $settings->cod_enabled,
        ];
    }

    public function assertCheckoutAllowed(float $orderSubtotal, string $paymentType): void
    {
        $settings = $this->current(create: false);
        if (! $settings) {
            return;
        }
        $state = $this->operatingState($settings, true);
        if (! $state['checkout_allowed']) {
            throw ValidationException::withMessages(['checkout' => ['Online Store checkout is currently unavailable.']]);
        }
        if ($paymentType === 'cash' && ! $state['cod_allowed']) {
            throw ValidationException::withMessages(['payment.type' => ['Cash on delivery is currently unavailable.']]);
        }
        if ($orderSubtotal + 0.0001 < (float) $settings->minimum_order) {
            throw ValidationException::withMessages(['items' => ['The order does not meet the configured minimum order amount.']]);
        }
    }

    private function defaults(): array
    {
        return [
            'id' => OnlineStoreSettings::SINGLETON_ID,
            'store_enabled' => false,
            'maintenance_mode' => false,
            'checkout_enabled' => false,
            'cod_enabled' => false,
            'guest_browsing_enabled' => true,
            'minimum_order' => 0,
            'enabled_languages' => ['ar'],
            'out_of_stock_behavior' => OnlineStoreSettings::OUT_OF_STOCK_BEHAVIOR,
            'low_stock_threshold' => 0,
        ];
    }

    private function validate(array $data): void
    {
        $languages = array_values(array_unique($data['enabled_languages'] ?? []));
        if ($languages === [] || array_diff($languages, self::LANGUAGES) !== []) {
            throw ValidationException::withMessages(['enabled_languages' => ['At least one supported Store language is required.']]);
        }
        if ((float) ($data['minimum_order'] ?? 0) < 0 || (int) ($data['low_stock_threshold'] ?? 0) < 0) {
            throw ValidationException::withMessages(['minimum_order' => ['Store monetary and stock thresholds cannot be negative.']]);
        }
        if (($data['out_of_stock_behavior'] ?? OnlineStoreSettings::OUT_OF_STOCK_BEHAVIOR) !== OnlineStoreSettings::OUT_OF_STOCK_BEHAVIOR) {
            throw ValidationException::withMessages(['out_of_stock_behavior' => ['V1 supports visible non-purchasable out-of-stock listings only.']]);
        }
    }
}
