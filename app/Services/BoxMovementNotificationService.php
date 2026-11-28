<?php

namespace App\Services;

use App\Models\Box;
use App\Models\BoxLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BoxMovementNotificationService
{
    public function recordLog(BoxLog $log): void
    {
        $this->safely(function () use ($log) {
            $amount = (float) ($log->value ?? $log->transfered_balance ?? 0);
            if (abs($amount) < 0.0001) {
                return;
            }

            $transfer = $log->from_box_id && $log->to_box_id;
            $ids = $transfer ? [$log->from_box_id, $log->to_box_id] : [$log->box_id];
            $boxes = Box::query()->whereIn('id', $ids)->get()->keyBy('id');
            $mainBoxes = $boxes->filter(fn (Box $box) => $this->isMainBox($box));
            if ($mainBoxes->isEmpty()) {
                return;
            }

            $data = [
                'box_log_id' => (string) $log->id,
                'movement_type' => $transfer ? 'transfer' : ($amount < 0 || $log->type === 'minus' ? 'withdrawal' : 'deposit'),
                'amount' => abs($amount),
                'description' => (string) $log->description,
                'note' => (string) $log->note,
                'reason_code' => (string) $log->reason_code,
                'source_type' => (string) $log->source_type,
                'source_id' => $log->source_id,
                'occurred_at' => $log->created_at?->toIso8601String(),
            ];
            $parts = [];
            if ($transfer) {
                $from = $boxes->get($log->from_box_id);
                $to = $boxes->get($log->to_box_id);
                $parts[] = 'تحويل '.$this->money(abs($amount)).' '.($from?->currency ?? $to?->currency)
                    .' من «'.($from?->name ?? '#'.$log->from_box_id).'» إلى «'.($to?->name ?? '#'.$log->to_box_id).'»';
                $data['from_box_id'] = (string) $log->from_box_id;
                $data['to_box_id'] = (string) $log->to_box_id;
            }
            foreach ($mainBoxes as $box) {
                $signed = $transfer
                    ? ((int) $box->id === (int) $log->from_box_id ? -abs($amount) : abs($amount))
                    : ($log->type === 'minus' ? -abs($amount) : $amount);
                $effect = $this->effect($box, $signed);
                $data['boxes'][] = $effect;
                $parts[] = $this->effectText($effect);
            }
            $data['box_id'] = (string) $mainBoxes->first()->id;
            $data['currency'] = (string) $mainBoxes->first()->currency;
            $this->schedule($parts, $data, $log->created_by, 'box_log', (int) $log->id, $log->getConnection());
        });
    }

    /** Notify reversals/restorations which deliberately remove or restore old ledger rows. */
    public function balanceChanged(Box $box, float $signedAmount, string $description, string $relatedType, int $relatedId, ?int $actorId = null): void
    {
        $this->safely(function () use ($box, $signedAmount, $description, $relatedType, $relatedId, $actorId) {
            if (! $this->isMainBox($box) || abs($signedAmount) < 0.0001) {
                return;
            }
            $effect = $this->effect($box, $signedAmount);
            $this->schedule([$this->effectText($effect)], [
                'box_id' => (string) $box->id,
                'movement_type' => $signedAmount < 0 ? 'withdrawal' : 'deposit',
                'amount' => abs($signedAmount),
                'currency' => (string) $box->currency,
                'description' => $description,
                'boxes' => [$effect],
                'occurred_at' => now()->toIso8601String(),
            ], $actorId, $relatedType, $relatedId, $box->getConnection());
        });
    }

    private function isMainBox(Box $box): bool
    {
        return ! $box->isDailySalesBox() && ! $box->isDailySalesOrdersBox() && ! $box->isDailyMaintenanceBox();
    }

    private function effect(Box $box, float $signedAmount): array
    {
        return [
            'box_id' => (int) $box->id,
            'box_name' => (string) $box->name,
            'currency' => (string) $box->currency,
            'change' => round($signedAmount, 4),
            'balance_before' => round((float) $box->total - $signedAmount, 4),
            'balance_after' => round((float) $box->total, 4),
        ];
    }

    private function effectText(array $effect): string
    {
        $action = $effect['change'] < 0 ? 'سحب' : 'إضافة';

        return $action.' '.$this->money(abs($effect['change'])).' '.$effect['currency'].' '.($action === 'سحب' ? 'من' : 'إلى')
            .' صندوق «'.$effect['box_name'].'»؛ الرصيد من '.$this->money($effect['balance_before'])
            .' إلى '.$this->money($effect['balance_after']).' '.$effect['currency'];
    }

    private function money(float $amount): string
    {
        return number_format($amount, round($amount, 2) === round($amount, 4) ? 2 : 4, '.', ',');
    }

    private function schedule(array $parts, array $data, ?int $actorId, string $relatedType, int $relatedId, \Illuminate\Database\Connection $connection): void
    {
        $actorId ??= auth()->id();
        $actorName = $actorId ? User::withTrashed()->find($actorId)?->name : null;
        $data['actor_id'] = $actorId;
        $data['actor_name'] = $actorName ?? ($actorId ? 'مستخدم #'.$actorId : 'النظام');
        $reason = [
            'owner_contribution' => 'إيداع من المالك', 'owner_withdrawal' => 'سحب من المالك',
            'cash_overage' => 'زيادة نقدية', 'cash_shortage' => 'عجز نقدي',
            'accounting_correction' => 'تصحيح محاسبي',
        ][$data['reason_code'] ?? ''] ?? null;
        foreach ([$data['description'] ?? null, $reason, $data['note'] ?? null] as $detail) {
            if (filled($detail)) {
                $parts[] = $detail;
            }
        }
        $parts[] = 'بواسطة: '.$data['actor_name'];
        $parts[] = 'الوقت: '.now(config('app.timezone'))->format('Y-m-d H:i:s');
        $body = implode("\n", $parts);

        $connection->afterCommit(function () use ($body, $data, $relatedType, $relatedId) {
            $this->safely(fn () => app(AdminNotificationService::class)->create(
                AdminNotificationService::TYPE_MAIN_BOX_MOVEMENT,
                'حركة على الصناديق الرئيسية',
                $body,
                $data,
                relatedType: $relatedType,
                relatedId: $relatedId,
            ));
        });
    }

    private function safely(callable $callback): void
    {
        try {
            if (Schema::hasTable('admin_notifications')) {
                $callback();
            }
        } catch (\Throwable $error) {
            Log::error('Main box movement notification failed', ['error' => $error->getMessage()]);
        }
    }
}
