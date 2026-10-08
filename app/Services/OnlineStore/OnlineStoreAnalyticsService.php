<?php

namespace App\Services\OnlineStore;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OnlineStoreAnalyticsService
{
    /** @param array<string, int> $increments */
    public function record(Request $request, array $increments, bool $visitor = false): void
    {
        if (! Schema::hasTable('online_store_daily_metrics')) {
            return;
        }

        $date = now()->toDateString();
        $timestamp = now();
        DB::table('online_store_daily_metrics')->insertOrIgnore([
            'metric_date' => $date,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        foreach ($increments as $column => $value) {
            if (! in_array($column, ['store_visits', 'product_views', 'section_views', 'banner_clicks'], true)) {
                continue;
            }
            DB::table('online_store_daily_metrics')
                ->where('metric_date', $date)
                ->increment($column, max(0, $value), ['updated_at' => $timestamp]);
        }

        if ($visitor && Schema::hasTable('online_store_daily_visitors')) {
            $identity = trim((string) $request->header('X-Store-Visitor-Id'));
            if ($identity === '') {
                $identity = implode('|', [(string) $request->ip(), (string) $request->userAgent()]);
            }
            DB::table('online_store_daily_visitors')->insertOrIgnore([
                'visit_date' => $date,
                'visitor_hash' => hash('sha256', $identity),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }
}
