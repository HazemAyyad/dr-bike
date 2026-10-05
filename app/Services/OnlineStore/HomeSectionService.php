<?php

namespace App\Services\OnlineStore;

use App\Models\OnlineStore\OnlineStoreHomeSection;
use App\Support\OnlineStore\OnlineStoreTargetRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HomeSectionService
{
    private const SELECTORS = [
        'categories' => ['active_categories'],
        'best_sellers' => ['best_sellers'],
        'recent' => ['recent'],
        'offers' => ['offers'],
        'custom' => ['featured', 'new', 'home', 'offers'],
    ];

    public function save(?OnlineStoreHomeSection $section, array $data, int $actorId): OnlineStoreHomeSection
    {
        $this->validateComposition($data['section_type'] ?? $section?->section_type, $data['selection_mode'] ?? $section?->selection_mode, $data['selection_config'] ?? $section?->selection_config);
        $section ??= new OnlineStoreHomeSection;
        $section->fill($data);
        if (! $section->exists) {
            $section->created_by = $actorId;
        }
        $section->updated_by = $actorId;
        $section->save();
        if ($section->selection_mode !== 'manual') {
            $section->items()->delete();
        }

        return $section->fresh('items');
    }

    public function replaceItems(OnlineStoreHomeSection $section, array $items): OnlineStoreHomeSection
    {
        if ($section->selection_mode !== 'manual') {
            throw ValidationException::withMessages(['items' => ['Only manual sections accept item rows.']]);
        }

        return DB::transaction(function () use ($section, $items) {
            $keys = [];
            foreach ($items as $item) {
                $type = $item['target_type'];
                if (! OnlineStoreTargetRegistry::sectionAcceptsTarget($section->section_type, $section->selection_mode, $type)) {
                    throw ValidationException::withMessages(['items' => ['The target type is incompatible with this section.']]);
                }
                $key = $type.':'.$item['target_id'];
                if (isset($keys[$key]) || ! DB::table(OnlineStoreTargetRegistry::targetTable($type))->where('id', $item['target_id'])->exists()) {
                    throw ValidationException::withMessages(['items' => ['Targets must be unique existing allow-listed records.']]);
                }
                $keys[$key] = true;
            }
            $section->items()->delete();
            foreach ($items as $item) {
                $section->items()->create($item);
            }

            return $section->fresh('items');
        });
    }

    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            $actual = OnlineStoreHomeSection::query()->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $provided = array_map('intval', $orderedIds);
            if (count($provided) !== count(array_unique($provided)) || collect($provided)->sort()->values()->all() !== $actual) {
                throw ValidationException::withMessages(['section_ids' => ['A reorder must include every home section exactly once.']]);
            }
            foreach ($provided as $order => $id) {
                OnlineStoreHomeSection::query()->whereKey($id)->update(['sort_order' => $order]);
            }
        });
    }

    private function validateComposition(?string $type, ?string $mode, mixed $config): void
    {
        if ($type === 'hero' && $mode !== 'dedicated_banners') {
            throw ValidationException::withMessages(['selection_mode' => ['Hero sections require dedicated_banners.']]);
        }
        if ($type === 'maintenance' && $mode !== 'automatic') {
            throw ValidationException::withMessages(['selection_mode' => ['Maintenance sections require automatic mode.']]);
        }
        if (! in_array($type, ['hero', 'maintenance'], true) && $mode === 'dedicated_banners') {
            throw ValidationException::withMessages(['selection_mode' => ['Dedicated banners are only valid for hero sections.']]);
        }
        if ($type === 'hero' && $config !== null) {
            throw ValidationException::withMessages(['selection_config' => ['Hero sections use dedicated banners and no selection configuration.']]);
        }
        if ($mode === 'manual' && $config !== null) {
            throw ValidationException::withMessages(['selection_config' => ['Manual sections do not accept selection configuration.']]);
        }
        if ($mode !== 'automatic') {
            return;
        }
        if (! is_array($config)) {
            throw ValidationException::withMessages(['selection_config' => ['Automatic sections require validated configuration.']]);
        }
        $allowedKeys = $type === 'maintenance' ? ['destination', 'content_key'] : ['selector', 'limit'];
        if (array_diff(array_keys($config), $allowedKeys)) {
            throw ValidationException::withMessages(['selection_config' => ['Unsupported configuration keys are not allowed.']]);
        }
        if ($type === 'maintenance') {
            $reference = $config['destination'] ?? $config['content_key'] ?? null;
            if (! is_string($reference) || ! preg_match('/^[a-z0-9._-]{1,100}$/i', $reference)) {
                throw ValidationException::withMessages(['selection_config' => ['A valid maintenance destination or content key is required.']]);
            }

            return;
        }
        if (! in_array($config['selector'] ?? null, self::SELECTORS[$type] ?? [], true)) {
            throw ValidationException::withMessages(['selection_config.selector' => ['The selector is not supported by the server.']]);
        }
        if (isset($config['limit']) && (! is_int($config['limit']) || $config['limit'] < 1 || $config['limit'] > 50)) {
            throw ValidationException::withMessages(['selection_config.limit' => ['The selector limit must be between 1 and 50.']]);
        }
    }
}
