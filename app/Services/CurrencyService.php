<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class CurrencyService
{
    protected $apiUrl = 'https://v6.exchangerate-api.com/v6';

    public function convertToShekel(float $amount, string $fromCurrency): float
    {
        // If already Shekel, just return the same amount
        if ($fromCurrency === 'شيكل') {
            return $amount;
        }

        $apiKey = env('FREECURRENCY_API_KEY');

        // Map Arabic currency names to ISO codes
        $baseCurrency = match ($fromCurrency) {
            'دولار' => 'USD',
            'دينار' => 'JOD',
            default => strtoupper($fromCurrency),
        };

        // ExchangeRate API uses this format:
        $response = Http::get("https://v6.exchangerate-api.com/v6/".$apiKey."/latest/".$baseCurrency);

        if ($response->failed()) {
            throw new \Exception("Currency conversion failed.");
        }

        $rate = $response->json('conversion_rates.ILS');

        if (!$rate) {
            throw new \Exception("Exchange rate for ILS not found.");
        }

        return $amount * $rate;
    }
}
