<?php

namespace App\Support;

class DebtLedgerLabels
{
    public const SCOPES = ['customers', 'sellers', 'private'];

    public static function normalize(array $labels): array
    {
        $general = self::pair($labels, ['taken_label' => 'أخذت', 'given_label' => 'أعطيت']);
        $byType = [];
        $scopes = (array) ($labels['by_type'] ?? []);
        foreach (self::SCOPES as $scope) {
            if (isset($scopes[$scope]) && is_array($scopes[$scope])) {
                $byType[$scope] = self::pair($scopes[$scope], $general);
            }
        }

        return $general + ['by_type' => (object) $byType];
    }

    public static function forScope(array $labels, ?string $scope = null): array
    {
        $normalized = self::normalize($labels);
        $byType = (array) $normalized['by_type'];

        return $byType[$scope ?? ''] ?? [
            'taken_label' => $normalized['taken_label'],
            'given_label' => $normalized['given_label'],
        ];
    }

    private static function pair(array $labels, array $fallback): array
    {
        $result = [];
        foreach (['taken_label', 'given_label'] as $key) {
            $result[$key] = trim((string) ($labels[$key] ?? '')) ?: $fallback[$key];
        }

        return $result;
    }
}
