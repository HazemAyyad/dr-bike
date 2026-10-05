<?php

namespace App\Services\OnlineStore;

use App\Models\DebtTransaction;
use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Models\OnlineStore\OnlineStoreCreditPolicy;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\DebtLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StoreCreditService
{
    public const CURRENCIES = OnlineStoreCreditPolicy::CURRENCIES;

    public const STORE_CHECKOUT_CURRENCY = 'ILS';

    private const LEDGER_CURRENCIES = ['ILS' => 'شيكل', 'USD' => 'دولار', 'JOD' => 'دينار'];

    public function savePolicy(User $actor, OnlineStoreAccountLink $link, array $data): OnlineStoreCreditPolicy
    {
        return DB::transaction(function () use ($actor, $link, $data) {
            $link = OnlineStoreAccountLink::query()->lockForUpdate()->findOrFail($link->getKey());
            $this->assertActiveVerifiedLink($link);
            $currency = strtoupper(trim((string) ($data['currency'] ?? 'ILS')));
            try {
                $limit = OnlineStoreCreditPolicy::normalizeLimit($data['credit_limit'] ?? null);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['credit_limit' => ['The credit limit cannot be negative.']]);
            }
            if (! in_array($currency, self::CURRENCIES, true)) {
                throw ValidationException::withMessages(['currency' => ['The credit currency is unsupported.']]);
            }

            $policy = OnlineStoreCreditPolicy::query()->where('account_link_id', $link->getKey())->lockForUpdate()->first();
            $policy ??= new OnlineStoreCreditPolicy(['account_link_id' => $link->getKey(), 'created_by' => $actor->getKey()]);
            $before = $policy->exists ? $policy->getAttributes() : null;
            $eligible = (bool) ($data['is_eligible'] ?? $policy->is_eligible ?? false);
            $policy->fill([
                'is_eligible' => $eligible,
                'credit_limit' => $limit,
                'currency' => $currency,
                'expires_at' => $data['expires_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor->getKey(),
            ]);
            if ($eligible) {
                $policy->approved_by = $actor->getKey();
                $policy->approved_at = now();
            } else {
                $policy->approved_by = null;
                $policy->approved_at = null;
            }
            $policy->save();
            app(OnlineStoreAuditService::class)->record($actor, $eligible ? 'approved' : 'updated', 'credit_policy', (int) $policy->id, $before, $policy->getAttributes());

            return $policy->fresh(['accountLink', 'approvedBy', 'createdBy', 'updatedBy']);
        });
    }

    public function summary(OnlineStoreAccountLink $link, ?string $currency = null, bool $lock = false): array
    {
        $linkQuery = OnlineStoreAccountLink::query()->with('creditPolicy')->whereKey($link->getKey());
        if ($lock) {
            $linkQuery->lockForUpdate();
        }
        $link = $linkQuery->firstOrFail();
        $policyQuery = OnlineStoreCreditPolicy::query()->where('account_link_id', $link->getKey());
        if ($lock) {
            $policyQuery->lockForUpdate();
        }
        $policy = $policyQuery->first();
        $currency = strtoupper(trim((string) ($currency ?? $policy?->currency ?? 'ILS')));
        if (! in_array($currency, self::CURRENCIES, true)) {
            throw ValidationException::withMessages(['currency' => ['The credit currency is unsupported.']]);
        }
        [$customerId, $sellerId] = $this->partyIds($link);
        $ledgerCurrency = self::LEDGER_CURRENCIES[$currency];
        $ledgerQuery = DebtTransaction::query()->active()->where('currency', $ledgerCurrency)
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId)->whereNull('seller_id'))
            ->when($sellerId, fn ($query) => $query->where('seller_id', $sellerId)->whereNull('customer_id'));
        if ($lock) {
            $ledgerQuery->lockForUpdate();
        }
        $ledgerRows = $ledgerQuery->get(['type', 'amount']);
        $currentDebt = round(max(0, (float) $ledgerRows->where('type', 'given')->sum('amount') - (float) $ledgerRows->where('type', 'taken')->sum('amount')), 2);
        $pendingExposure = $this->pendingOrderExposure($link, $ledgerCurrency, $lock);
        $totalExposure = round($currentDebt + $pendingExposure, 2);
        $limit = $policy?->credit_limit !== null ? (float) $policy->credit_limit : null;

        return [
            'policy' => $policy,
            'currency' => $currency,
            'ledger_currency' => $ledgerCurrency,
            'current_debt' => $currentDebt,
            'pending_order_exposure' => $pendingExposure,
            'total_exposure' => $totalExposure,
            'is_unlimited' => $limit === null,
            'available_credit' => $limit === null ? null : max(0, round($limit - $totalExposure, 2)),
            'as_of' => now()->toISOString(),
        ];
    }

    public function assertCanCheckout(OnlineStoreAccountLink $link, float $unpaidAmount, string $currency = 'ILS'): array
    {
        $this->assertActiveVerifiedLink($link);
        $summary = $this->summary($link, $currency, true);
        /** @var OnlineStoreCreditPolicy|null $policy */
        $policy = $summary['policy'];
        if (! $policy || ! $policy->is_eligible || ! $policy->approved_at) {
            throw ValidationException::withMessages(['payment.type' => ['This Store account is not approved for credit.']]);
        }
        if (! $policy->isApprovedAt(now())) {
            throw ValidationException::withMessages(['payment.type' => ['This Store credit approval has expired.']]);
        }
        if ($policy->currency !== strtoupper($currency)) {
            throw ValidationException::withMessages(['payment.currency' => ['The payment currency does not match the approved credit policy.']]);
        }
        if ($summary['available_credit'] !== null && $unpaidAmount > $summary['available_credit'] + 0.0001) {
            throw ValidationException::withMessages(['payment.paid_amount' => ['The unpaid amount exceeds available Store credit.']]);
        }

        return $summary;
    }

    private function assertActiveVerifiedLink(OnlineStoreAccountLink $link): void
    {
        if (! $link->isVerifiedCreditIdentity() || ! $link->party()) {
            throw ValidationException::withMessages(['account_link' => ['Credit requires an active verified Store account link.']]);
        }
    }

    private function partyIds(OnlineStoreAccountLink $link): array
    {
        if ($link->role === 'customer' && $link->customer_id && ! $link->seller_id) {
            return [(int) $link->customer_id, null];
        }
        if ($link->role === 'seller' && $link->seller_id && ! $link->customer_id) {
            return [null, (int) $link->seller_id];
        }

        throw new \LogicException('A Store credit policy must resolve exactly one party dimension.');
    }

    private function pendingOrderExposure(OnlineStoreAccountLink $link, string $ledgerCurrency, bool $lock): float
    {
        if ($ledgerCurrency !== DebtLedgerService::CURRENCIES[0]) {
            return 0.0;
        }
        $query = SalesOrder::query()->where('origin', SalesOrder::ORIGIN_STORE)
            ->whereIn('payment_type', ['credit', 'mixed'])
            ->whereNull('financial_posted_at')
            ->whereNotIn('status', ['canceled', 'archived', 'returned'])
            ->where('is_debt_collection', false)
            ->whereNotExists(function ($debt) {
                $debt->selectRaw('1')->from('debt_transactions as pending_exposure_debt')
                    ->whereColumn('pending_exposure_debt.source_id', 'sales_orders.id')
                    ->where('pending_exposure_debt.source', 'sales_order')
                    ->whereNull('pending_exposure_debt.archived_at')
                    ->whereNull('pending_exposure_debt.deleted_at');
            })
            ->when($link->role === 'customer', fn ($q) => $q->where('partner_type', 'customer')->where('partner_id', $link->customer_id))
            ->when($link->role === 'seller', fn ($q) => $q->where('partner_type', 'seller')->where('partner_id', $link->seller_id));
        if ($lock) {
            $query->lockForUpdate();
        }

        return round((float) $query->get(['total', 'payment_amount'])->sum(fn (SalesOrder $order) => max(0, (float) $order->total - (float) $order->payment_amount)), 2);
    }
}
