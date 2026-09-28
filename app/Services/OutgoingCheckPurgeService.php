<?php

namespace App\Services;

use App\Models\AccountingJournalEntry;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\OutgoingCheck;
use App\Models\Log;
use Illuminate\Support\Facades\DB;

class OutgoingCheckPurgeService
{
    /** @return array{front: array<int,string>, back: array<int,string>} */
    public function purge(OutgoingCheck $check): array
    {
        return DB::transaction(function () use ($check) {
            $root = OutgoingCheck::query()->lockForUpdate()->findOrFail($check->id);
            $checks = OutgoingCheck::query()
                ->where('id', $root->id)
                ->orWhere('parent_outgoing_check_id', $root->id)
                ->lockForUpdate()
                ->get();
            $checkIds = $checks->pluck('id')->map(fn ($id) => (int) $id);
            $settlements = DB::table('outgoing_check_settlements')
                ->whereIn('outgoing_check_id', $checkIds)->lockForUpdate()->get();

            foreach ($settlements->whereNotNull('box_id')->groupBy('box_id') as $boxId => $rows) {
                Box::query()->lockForUpdate()->findOrFail($boxId)
                    ->increment('total', round((float) $rows->sum('amount'), 4));
            }
            foreach ($checks->where('status', 'cashed_from_box')->whereNotNull('box_id')->groupBy('box_id') as $boxId => $rows) {
                Box::query()->lockForUpdate()->findOrFail($boxId)
                    ->increment('total', round((float) $rows->sum('total'), 4));
            }

            $settlementIds = $settlements->pluck('id')->map(fn ($id) => (int) $id);
            if ($settlementIds->isNotEmpty()) {
                BoxLog::query()->where('source_type', 'outgoing_check_settlement')
                    ->whereIn('source_id', $settlementIds)->delete();
            }
            BoxLog::query()->where('source_type', 'outgoing_check')
                ->whereIn('source_id', $checkIds)->delete();
            Log::query()->where(function ($query) use ($checkIds, $settlementIds, $checks) {
                $query->where(fn ($q) => $q->where('source_type', 'outgoing_check')->whereIn('source_id', $checkIds));
                if ($settlementIds->isNotEmpty()) {
                    $query->orWhere(fn ($q) => $q->where('source_type', 'outgoing_check_settlement')->whereIn('source_id', $settlementIds));
                }
                foreach ($checks->pluck('check_id')->filter()->unique() as $number) {
                    $query->orWhere(fn ($q) => $q
                        ->where('type', 'outgoing_checks')
                        ->where(function ($legacy) use ($number) {
                            $legacy->where('description', 'تمت إضافة شيك جديد برقم '.$number)
                                ->orWhere('description', 'like', '% من الشيك رقم '.$number.' والمتبقي %');
                        }));
                }
            })->delete();

            $journalIds = AccountingJournalEntry::query()
                ->where(function ($query) use ($checkIds, $settlementIds) {
                    $query->where(fn ($q) => $q->where('source_type', 'outgoing_check')->whereIn('source_id', $checkIds));
                    if ($settlementIds->isNotEmpty()) {
                        $query->orWhere(fn ($q) => $q->where('source_type', 'outgoing_check_settlement')->whereIn('source_id', $settlementIds));
                    }
                })->pluck('id');
            AccountingJournalEntry::query()->whereIn('reverses_entry_id', $journalIds)->delete();
            AccountingJournalEntry::query()->whereIn('id', $journalIds)->delete();

            DB::table('accounting_projection_failures')->where(function ($query) use ($checkIds, $settlementIds) {
                $query->where(fn ($q) => $q->where('source_type', 'outgoing_check')->whereIn('source_id', $checkIds));
                if ($settlementIds->isNotEmpty()) {
                    $query->orWhere(fn ($q) => $q->where('source_type', 'outgoing_check_settlement')->whereIn('source_id', $settlementIds));
                }
            })->delete();

            DB::table('debt_transactions')->where('source', 'outgoing_check')->whereIn('source_id', $checkIds)->delete();
            DB::table('outgoing_check_installments')->whereIn('outgoing_check_id', $checkIds)->delete();
            DB::table('outgoing_check_settlements')->whereIn('outgoing_check_id', $checkIds)->delete();

            $files = [
                'front' => $checks->pluck('img')->filter()->unique()->values()->all(),
                'back' => $checks->pluck('back_image')->filter()->unique()->values()->all(),
            ];
            OutgoingCheck::withoutEvents(fn () => OutgoingCheck::query()->whereIn('id', $checkIds)->delete());

            return $files;
        }, 3);
    }
}
