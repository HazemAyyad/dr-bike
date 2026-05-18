<?php

namespace App\Http\Controllers;

use App\Services\DebtLedgerService;
use Illuminate\Support\Facades\Cache;

class DebtLedgerShareWebController extends Controller
{
    public function __construct(private DebtLedgerService $ledger)
    {
    }

    public function show(string $token)
    {
        $payload = Cache::get('debt_ledger_share:' . $token);

        if (! is_array($payload)) {
            abort(404);
        }

        $customerId = $payload['customer_id'] ?? null;
        $sellerId = $payload['seller_id'] ?? null;

        $person = $this->ledger->getPersonInfo($customerId, $sellerId);
        $transactions = $this->ledger->baseQuery($customerId, $sellerId)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get();

        $totals = $this->ledger->calculateTotals($customerId, $sellerId);

        return view('debt-ledger.public-share', [
            'person' => $person,
            'transactions' => $transactions,
            'total_taken' => $totals['total_taken'],
            'total_given' => $totals['total_given'],
            'balance' => $totals['balance'],
        ]);
    }
}
