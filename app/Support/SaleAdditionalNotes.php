<?php

namespace App\Support;

final class SaleAdditionalNotes
{
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $notes = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? $item['note'] ?? $item['description'] ?? ''));
            $amount = max(0, (float) ($item['amount'] ?? $item['price'] ?? $item['value'] ?? 0));
            if ($text === '' && $amount <= 0) {
                continue;
            }

            $notes[] = ['text' => $text, 'amount' => round($amount, 2)];
        }

        return $notes;
    }

    public static function total(array $notes): float
    {
        return round(array_reduce(
            $notes,
            fn (float $sum, array $note) => $sum + (float) ($note['amount'] ?? 0),
            0.0
        ), 2);
    }
}
