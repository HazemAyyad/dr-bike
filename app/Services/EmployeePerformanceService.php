<?php

namespace App\Services;

use App\Models\EmployeeDetail;
use App\Models\Goal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeePerformanceService
{
    public function report(EmployeeDetail $employee, string $period = 'monthly'): array
    {
        [$start, $end] = $this->range($period);
        $current = $this->calculate($employee, $start, $end);
        $days = $start->diffInDays($end) + 1;
        $previousEnd = $start->copy()->subSecond();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();
        $previous = $this->calculate($employee, $previousStart, $previousEnd);
        $change = $previous['score'] === null || $current['score'] === null
            ? null
            : round($current['score'] - $previous['score'], 1);

        return array_merge($current, [
            'period' => $period,
            'range' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'comparison' => [
                'change' => $change,
                'previous_score' => $previous['score'],
            ],
            'rating' => $this->rating($current['score']),
            'improvement_tip' => $this->improvementTip($current['sections']),
        ]);
    }

    private function calculate(EmployeeDetail $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        $sections = [
            $this->tasks($employee, $start, $end),
            $this->goals($employee, $start, $end),
            $this->attendance($employee, $start, $end),
        ];

        $social = $this->social($employee, $start, $end);
        if ($social !== null) {
            $sections[] = $social;
        }

        $available = collect($sections)->where('available', true);
        $weight = (float) $available->sum('weight');
        $score = $weight > 0
            ? round((float) $available->sum(fn (array $section) => $section['score'] * $section['weight']) / $weight, 1)
            : null;

        return [
            'score' => $score,
            'sections' => array_values($sections),
        ];
    }

    private function tasks(EmployeeDetail $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        if (! Schema::hasTable('employee_tasks')) {
            return $this->section('tasks', 'المهام', 30, null, ['total' => 0, 'completed' => 0]);
        }

        $query = DB::table('employee_tasks')
            ->where(function ($query) use ($employee) {
                if (Schema::hasColumn('employee_tasks', 'employee_id')) {
                    $query->where('employee_tasks.employee_id', $employee->id);
                } else {
                    $query->whereRaw('1 = 0');
                }
                if (Schema::hasTable('employee_task_assignees')) {
                    $query->orWhereExists(function ($sub) use ($employee) {
                        $sub->selectRaw('1')
                            ->from('employee_task_assignees')
                            ->whereColumn('employee_task_assignees.employee_task_id', 'employee_tasks.id')
                            ->where('employee_task_assignees.employee_id', $employee->id);
                    });
                }
            });
        if (Schema::hasColumn('employee_tasks', 'is_canceled')) {
            $query->where(function ($query) {
                $query->whereNull('is_canceled')->orWhere('is_canceled', false);
            });
        }

        $dateColumn = Schema::hasColumn('employee_tasks', 'created_at') ? 'created_at' : 'start_time';
        $query->whereBetween($dateColumn, [$start, $end]);
        $total = (clone $query)->count();
        $completed = Schema::hasColumn('employee_tasks', 'status')
            ? (clone $query)->where('status', 'completed')->count()
            : 0;
        $score = $total > 0 ? round(($completed / $total) * 100, 1) : null;

        return $this->section('tasks', 'المهام', 30, $score, [
            'total' => $total,
            'completed' => $completed,
            'remaining' => max(0, $total - $completed),
        ]);
    }

    private function goals(EmployeeDetail $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        if (
            ! Schema::hasTable('goals')
            || (! Schema::hasColumn('goals', 'employee_id') && ! Schema::hasTable('goal_employee_shares'))
            || ! Schema::hasColumn('goals', 'achievement_percentage')
        ) {
            return $this->section('goals', 'الأهداف', 30, null, ['total' => 0]);
        }

        $goals = Goal::query()
            ->where(function ($query) use ($employee) {
                if (Schema::hasColumn('goals', 'employee_id')) {
                    $query->where('employee_id', $employee->id);
                } else {
                    $query->whereRaw('1 = 0');
                }
                if (Schema::hasTable('goal_employee_shares')) {
                    $query->orWhereExists(function ($sub) use ($employee) {
                        $sub->selectRaw('1')
                            ->from('goal_employee_shares')
                            ->whereColumn('goal_employee_shares.goal_id', 'goals.id')
                            ->where('goal_employee_shares.employee_id', $employee->id);
                    });
                }
            });
        if (Schema::hasColumn('goals', 'is_canceled')) {
            $goals->where(function ($query) {
                $query->whereNull('is_canceled')->orWhere('is_canceled', false);
            });
        }
        if (Schema::hasColumn('goals', 'start_date')) {
            $goals->where(function ($query) use ($end) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $end->toDateString());
            });
        }
        if (Schema::hasColumn('goals', 'due_date')) {
            $goals->where(function ($query) use ($start) {
                $query->whereNull('due_date')->orWhereDate('due_date', '>=', $start->toDateString());
            });
        } elseif (Schema::hasColumn('goals', 'created_at')) {
            $goals->where('created_at', '<=', $end);
        }
        $goals = $goals->get(['id', 'achievement_percentage']);

        $score = $goals->isEmpty()
            ? null
            : round((float) $goals->avg(fn (Goal $goal) => min(100, max(0, (float) $goal->achievement_percentage))), 1);

        return $this->section('goals', 'الأهداف', 30, $score, [
            'total' => $goals->count(),
            'achieved' => $goals->filter(fn (Goal $goal) => (float) $goal->achievement_percentage >= 100)->count(),
        ]);
    }

    private function attendance(EmployeeDetail $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        if (! Schema::hasTable('employee_attendances')) {
            return $this->section('attendance', 'الالتزام', 40, null, ['days' => 0]);
        }

        $rows = DB::table('employee_attendances')
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['worked_minutes', 'required_minutes', 'arrived_at', 'left_at', 'missing_checkout']);

        $dailyScores = $rows->map(function ($row) {
            if ((bool) ($row->missing_checkout ?? false) || empty($row->arrived_at)) {
                return 0.0;
            }
            $required = (int) ($row->required_minutes ?? 0);
            if ($required <= 0) {
                return empty($row->left_at) ? 50.0 : 100.0;
            }
            return min(100, max(0, ((int) ($row->worked_minutes ?? 0) / $required) * 100));
        });
        $score = $dailyScores->isEmpty() ? null : round((float) $dailyScores->avg(), 1);

        return $this->section('attendance', 'الالتزام', 40, $score, [
            'days' => $rows->count(),
            'complete_days' => $rows->filter(fn ($row) => $row->arrived_at && $row->left_at && ! $row->missing_checkout)->count(),
            'missing_checkout_days' => $rows->where('missing_checkout', true)->count(),
        ]);
    }

    private function social(EmployeeDetail $employee, CarbonInterface $start, CarbonInterface $end): ?array
    {
        if (! Schema::hasTable('employee_permissions') || ! Schema::hasTable('permissions')) {
            return null;
        }

        $channels = $employee->permissions()
            ->whereHas('permission', fn ($query) => $query->whereIn('name_en', [
                'Social Center WhatsApp', 'Social Center Facebook', 'Social Center Instagram',
            ]))
            ->with('permission:id,name_en')
            ->get()
            ->map(fn ($row) => match ($row->permission?->name_en) {
                'Social Center WhatsApp' => 'whatsapp',
                'Social Center Facebook' => 'facebook',
                'Social Center Instagram' => 'instagram',
                default => null,
            })
            ->filter()->unique()->values();

        if ($channels->isEmpty()) {
            return null;
        }

        $metrics = collect();
        if ($channels->contains('whatsapp') && Schema::hasTable('whatsapp_messages')) {
            $metrics->push($this->socialChannelMetrics(
                'whatsapp', 'whatsapp_conversations', 'whatsapp_messages',
                'whatsapp_conversation_id', (int) $employee->user_id, $start, $end
            ));
        }
        foreach (['facebook', 'instagram'] as $channel) {
            if ($channels->contains($channel) && Schema::hasTable('social_messages')) {
                $metrics->push($this->socialChannelMetrics(
                    $channel, 'social_conversations', 'social_messages',
                    'social_conversation_id', (int) $employee->user_id, $start, $end, $channel
                ));
            }
        }

        $handled = (int) $metrics->sum('handled_conversations');
        $responses = (int) $metrics->sum('response_samples');
        $weightedMinutes = (float) $metrics->sum(fn (array $row) => $row['average_first_response_minutes'] * $row['response_samples']);
        $averageMinutes = $responses > 0 ? round($weightedMinutes / $responses, 1) : null;
        $needsReply = (int) $metrics->sum('needs_reply');
        $closed = (int) $metrics->sum('closed');
        $resolutionRate = $handled > 0 ? round(min(100, ($closed / $handled) * 100), 1) : null;
        $speedScore = $averageMinutes === null ? null : match (true) {
            $averageMinutes <= 5 => 100,
            $averageMinutes <= 15 => 85,
            $averageMinutes <= 30 => 70,
            $averageMinutes <= 60 => 55,
            default => 30,
        };
        $backlogScore = $handled > 0 ? max(0, 100 - min(100, ($needsReply / $handled) * 100)) : null;
        $parts = collect([$speedScore, $resolutionRate, $backlogScore])->filter(fn ($value) => $value !== null);
        $score = $parts->isEmpty() ? null : round((float) $parts->avg(), 1);

        return $this->section('social', 'خدمة العملاء', 35, $score, [
            'channels' => $metrics->values()->all(),
            'handled_conversations' => $handled,
            'average_first_response_minutes' => $averageMinutes,
            'resolution_rate' => $resolutionRate,
            'needs_reply' => $needsReply,
            'sample_sufficient' => $handled >= 3,
        ]);
    }

    private function socialChannelMetrics(
        string $name,
        string $conversationTable,
        string $messageTable,
        string $conversationKey,
        int $userId,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $channel = null
    ): array {
        $outgoing = DB::table($messageTable)
            ->where('sent_by', $userId)
            ->where('direction', 'outbound')
            ->whereBetween('created_at', [$start, $end])
            ->when($channel, fn ($query) => $query->where('channel', $channel))
            ->get(['id', $conversationKey, 'created_at']);
        $conversationIds = $outgoing->pluck($conversationKey)->filter()->unique()->values();
        $assigned = DB::table($conversationTable)
            ->where('assigned_admin_id', $userId)
            ->when($channel, fn ($query) => $query->where('channel', $channel));
        $needsReply = (clone $assigned)->whereRaw(
            "(SELECT MAX(created_at) FROM {$messageTable} WHERE {$messageTable}.{$conversationKey} = {$conversationTable}.id AND direction = ?) > COALESCE((SELECT MAX(created_at) FROM {$messageTable} WHERE {$messageTable}.{$conversationKey} = {$conversationTable}.id AND direction = ?), '1000-01-01')",
            ['inbound', 'outbound']
        )->count();
        $closed = (clone $assigned)->where('status', 'closed')->whereIn('id', $conversationIds)->count();

        $responseMinutes = collect();
        foreach ($conversationIds as $conversationId) {
            $firstOutgoing = $outgoing->where($conversationKey, $conversationId)->sortBy('created_at')->first();
            if (! $firstOutgoing) continue;
            $inboundAt = DB::table($messageTable)
                ->where($conversationKey, $conversationId)
                ->where('direction', 'inbound')
                ->where('created_at', '<=', $firstOutgoing->created_at)
                ->latest('created_at')->value('created_at');
            if ($inboundAt) {
                $responseMinutes->push(Carbon::parse($inboundAt)->diffInMinutes(Carbon::parse($firstOutgoing->created_at)));
            }
        }

        return [
            'channel' => $name,
            'handled_conversations' => $conversationIds->count(),
            'average_first_response_minutes' => $responseMinutes->isEmpty() ? 0 : round((float) $responseMinutes->avg(), 1),
            'response_samples' => $responseMinutes->count(),
            'closed' => $closed,
            'needs_reply' => $needsReply,
        ];
    }

    private function section(string $key, string $label, int $weight, ?float $score, array $metrics): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'score' => $score,
            'weight' => $weight,
            'available' => $score !== null,
            'metrics' => $metrics,
        ];
    }

    private function range(string $period): array
    {
        $now = now(config('app.timezone'));
        return $period === 'weekly'
            ? [$now->copy()->startOfWeek(Carbon::SATURDAY), $now->copy()->endOfDay()]
            : [$now->copy()->startOfMonth(), $now->copy()->endOfDay()];
    }

    private function rating(?float $score): array
    {
        return match (true) {
            $score === null => ['key' => 'no_data', 'label' => 'لا توجد بيانات كافية'],
            $score >= 90 => ['key' => 'excellent', 'label' => 'أداء ممتاز'],
            $score >= 75 => ['key' => 'very_good', 'label' => 'أداء جيد جدًا'],
            $score >= 60 => ['key' => 'good', 'label' => 'أداء جيد'],
            default => ['key' => 'needs_improvement', 'label' => 'يحتاج إلى تحسين'],
        };
    }

    private function improvementTip(array $sections): string
    {
        $section = collect($sections)->where('available', true)->sortBy('score')->first();
        return match ($section['key'] ?? null) {
            'social' => 'تابع المحادثات المستلمة قبل نهاية الدوام.',
            'tasks' => 'ركّز على إنهاء المهام المفتوحة ضمن وقتها.',
            'goals' => 'راجع تقدم أهدافك يوميًا وحافظ على الاستمرارية.',
            'attendance' => 'حافظ على اكتمال تسجيل الحضور والانصراف.',
            default => 'ستظهر نصيحة التحسين بعد توفر بيانات كافية.',
        };
    }
}
