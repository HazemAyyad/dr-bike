<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class DeliveryQuoteUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Delivery pricing is temporarily unavailable. Please retry.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => 'delivery_quote_unavailable',
            'message' => $this->getMessage(),
        ], 503);
    }
}
