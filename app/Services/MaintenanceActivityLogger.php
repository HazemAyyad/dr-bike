<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceActivityLog;
use App\Models\User;

class MaintenanceActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        Maintenance $maintenance,
        ?User $actor,
        string $action,
        string $title,
        ?string $description = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        array $metadata = []
    ): MaintenanceActivityLog {
        $log = MaintenanceActivityLog::create([
            'maintenance_id' => $maintenance->id,
            'user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_type' => $actor?->type,
            'action' => $action,
            'title' => $title,
            'description' => $description,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        app(EmployeeActivityLogger::class)->log(
            null,
            $actor,
            'maintenance',
            $action,
            $title,
            $description,
            $maintenance,
            isset($metadata['amount']) ? (float) $metadata['amount'] : (float) ($maintenance->invoice_total ?? 0),
            $metadata
        );

        return $log;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if ($this->normalize($oldValue) === $this->normalize($newValue)) {
                continue;
            }

            $changes[$key] = [
                'old' => $oldValue,
                'new' => $newValue,
            ];
        }

        return $changes;
    }

    private function normalize(mixed $value): string
    {
        if (is_float($value) || is_int($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return trim((string) $value);
    }
}
