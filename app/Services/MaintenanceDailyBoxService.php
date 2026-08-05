<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Models\Box;
use App\Models\Maintenance;
use App\Models\MaintenanceDailyBoxLog;
use App\Models\MaintenanceDailyClosingRequest;
use App\Models\MaintenanceDailySession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceDailyBoxService
{
    /**
     * @return array{user_id: int, employee_id: int|null}
     */
    public function resolveOwner(User $user): array
    {
        return [
            'user_id' => (int) $user->id,
            'employee_id' => $user->employee?->id ? (int) $user->employee->id : null,
        ];
    }

    public function businessDate(?Carbon $at = null): Carbon
    {
        return ($at ?? now())->copy()->startOfDay();
    }

    public function findBlockingSession(User $user, ?Carbon $at = null): ?MaintenanceDailySession
    {
        $owner = $this->resolveOwner($user);
        $today = $this->businessDate($at)->toDateString();

        return MaintenanceDailySession::query()
            ->with(['box:id,name,total,currency,type', 'user:id,name'])
            ->where('user_id', $owner['user_id'])
            ->whereIn('status', [
                config('maintenance_daily.session_status.open', 'open'),
                config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
            ])
            ->whereDate('business_date', '<', $today)
            ->orderBy('business_date')
            ->first();
    }

    public function ensureBox(User $user): Box
    {
        $owner = $this->resolveOwner($user);
        $displayName = $user->name ?? 'مستخدم';

        $query = Box::query()
            ->where('type', config('maintenance_daily.box_type', 'daily_maintenance'))
            ->where('currency', config('maintenance_daily.currency', 'شيكل'));

        if ($owner['employee_id']) {
            $query->where('employee_id', $owner['employee_id']);
        } else {
            $query->where('user_id', $owner['user_id'])->whereNull('employee_id');
        }

        $box = $query->first();

        if ($box) {
            return $box;
        }

        return Box::create([
            'name' => config('maintenance_daily.box_name', 'صندوق الصيانة اليومي').' - '.$displayName,
            'type' => config('maintenance_daily.box_type', 'daily_maintenance'),
            'employee_id' => $owner['employee_id'],
            'user_id' => $owner['employee_id'] ? null : $owner['user_id'],
            'total' => 0,
            'is_shown' => 0,
            'currency' => config('maintenance_daily.currency', 'شيكل'),
        ]);
    }

    public function currentSession(User $user, ?Carbon $at = null): ?MaintenanceDailySession
    {
        $at = $at ?? now();
        $owner = $this->resolveOwner($user);
        $date = $this->businessDate($at);

        if ($blocking = $this->findBlockingSession($user, $at)) {
            return $blocking;
        }

        return MaintenanceDailySession::query()
            ->with(['box:id,name,total,currency,type'])
            ->where('user_id', $owner['user_id'])
            ->whereDate('business_date', $date)
            ->whereIn('status', [
                config('maintenance_daily.session_status.open', 'open'),
                config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
            ])
            ->orderByDesc('id')
            ->first();
    }

    public function findGlobalOpenSession(?int $exceptSessionId = null): ?MaintenanceDailySession
    {
        $query = MaintenanceDailySession::query()
            ->with('user')
            ->whereIn('status', [
                config('maintenance_daily.session_status.open', 'open'),
                config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
            ]);

        if ($exceptSessionId) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query
            ->orderBy('business_date')
            ->orderByDesc('id')
            ->first();
    }

    public function requireOpenSession(User $user, ?Carbon $at = null): MaintenanceDailySession
    {
        $at = $at ?? now();

        $session = $this->currentSession($user, $at);

        if (! $session || ! $session->isOpen()) {
            throw ValidationException::withMessages([
                'maintenance_daily_box' => ['يجب فتح صندوق الصيانة اليومي قبل تسليم الصيانة.'],
            ]);
        }

        return $session;
    }

    public function openToday(?User $user = null, ?Carbon $at = null, float $openingBalance = 0): MaintenanceDailySession
    {
        $at = $at ?? now();

        if (! $user) {
            throw ValidationException::withMessages([
                'maintenance_daily_box' => ['لا يمكن فتح صندوق الصيانة بدون مستخدم.'],
            ]);
        }

        return $this->openSession($this->businessDate($at), $user, $openingBalance);
    }

    private function openSession(Carbon $date, User $user, float $openingBalance = 0): MaintenanceDailySession
    {
        if ($this->findBlockingSession($user, $date)) {
            throw ValidationException::withMessages([
                'maintenance_daily_box' => ['يوجد صندوق صيانة سابق مفتوح، يجب إغلاقه قبل فتح صندوق يوم جديد.'],
            ]);
        }

        $session = DB::transaction(function () use ($user, $date, $openingBalance) {
            $owner = $this->resolveOwner($user);
            $box = Box::lockForUpdate()->find($this->ensureBox($user)->id);
            $session = MaintenanceDailySession::query()
                ->where('user_id', $owner['user_id'])
                ->whereDate('business_date', $date)
                ->whereIn('status', [
                    config('maintenance_daily.session_status.open', 'open'),
                    config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
                ])
                ->lockForUpdate()
                ->first();

            if ($session) {
                if (! $session->isOpen()) {
                    throw ValidationException::withMessages([
                        'maintenance_daily_box' => ['تم إغلاق صندوق الصيانة لهذا اليوم.'],
                    ]);
                }

                return $session;
            }

            if ($globalOpen = $this->findGlobalOpenSession()) {
                $employeeName = $globalOpen->user?->name ?? 'موظف';

                throw ValidationException::withMessages([
                    'maintenance_daily_box' => ["يوجد صندوق صيانة يومي مفتوح عند {$employeeName}."],
                ]);
            }

            $openingBalance = round(max(0, $openingBalance), 2);
            $box->total = $openingBalance;
            $box->save();

            return MaintenanceDailySession::create([
                'user_id' => $owner['user_id'],
                'employee_id' => $owner['employee_id'],
                'business_date' => $date->toDateString(),
                'status' => config('maintenance_daily.session_status.open', 'open'),
                'box_id' => $box->id,
                'opening_balance' => $openingBalance,
                'opened_at' => now(),
                'opened_by_user_id' => $user?->id,
            ]);
        });

        $this->logSessionActivity(
            $session,
            $user,
            'maintenance_daily_session_opened',
            'فتح صندوق الصيانة',
            'تم فتح صندوق الصيانة اليومي',
            ['opening_balance' => $openingBalance]
        );

        return $session;
    }

    public function requestClosing(User $user, ?string $note = null, ?Carbon $at = null): MaintenanceDailyClosingRequest
    {
        $at = $at ?? now();

        return DB::transaction(function () use ($user, $note, $at) {
            $session = $this->requireOpenSession($user, $at);
            $box = Box::lockForUpdate()->findOrFail($session->box_id);
            $pending = $session->closingRequests()->where('status', 'pending')->exists();
            if ($pending) {
                throw ValidationException::withMessages([
                    'session' => ['يوجد طلب إغلاق معلق لصندوق الصيانة.'],
                ]);
            }

            $cashCounts = [[
                'currency' => $box->currency,
                'daily_box_id' => (int) $box->id,
                'opening_float' => round((float) $session->opening_balance, 2),
                'sales_collected' => round(max(0, (float) $box->total - (float) $session->opening_balance), 2),
                'system_balance' => round((float) $box->total, 2),
                'physical_count' => round((float) $box->total, 2),
                'variance' => 0,
                'float_to_keep' => 0,
                'amount_to_transfer' => round((float) $box->total, 2),
                'employee_note' => $note ? trim($note) : '',
                'variance_alert' => false,
            ]];

            $session->update([
                'status' => config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
                'closing_balance' => round((float) $box->total, 2),
                'closing_requested_at' => now(),
                'closing_requested_by_user_id' => $user->id,
                'closing_request_note' => $note ? trim($note) : null,
            ]);

            $request = MaintenanceDailyClosingRequest::create([
                'session_id' => $session->id,
                'requested_by_user_id' => $user->id,
                'requested_at' => now(),
                'status' => 'pending',
                'maintenances_count' => Maintenance::query()
                    ->where('maintenance_daily_session_id', $session->id)
                    ->count(),
                'cash_counts' => $cashCounts,
            ]);

            $this->notifyClosingRequested($session->fresh(['user']), $request, $user);
            $this->logSessionActivity(
                $session,
                $user,
                'maintenance_daily_closing_requested',
                'طلب إغلاق صندوق الصيانة',
                'تم طلب إغلاق صندوق الصيانة اليومي',
                [
                    'closing_request_id' => (int) $request->id,
                    'note' => $note ? trim($note) : null,
                ],
                'maintenance_daily_closing_request',
                (int) $request->id
            );

            return $request->fresh(['session.user', 'requestedBy']);
        });
    }

    public function pendingClosingRequests()
    {
        return MaintenanceDailyClosingRequest::query()
            ->with(['session.box:id,name,total,currency,type', 'session.user:id,name', 'requestedBy:id,name'])
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MaintenanceDailyClosingRequest $request) => $this->formatClosingRequest($request))
            ->values();
    }

    public function listOpenSessions(User $viewer)
    {
        if (! $this->canReviewClosing($viewer)) {
            throw ValidationException::withMessages([
                'session' => [__('messages.unauthorized')],
            ]);
        }

        return MaintenanceDailySession::query()
            ->with(['box:id,name,total,currency,type', 'user:id,name', 'closingRequests'])
            ->whereIn('status', [
                config('maintenance_daily.session_status.open', 'open'),
                config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
            ])
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (MaintenanceDailySession $session) {
                $pendingClosing = $session->closingRequests
                    ->where('status', 'pending')
                    ->sortByDesc('id')
                    ->first();
                $payload = $this->payload($session->business_date?->toDateString(), $session->user);

            return [
                'id' => (int) $session->id,
                'session_id' => (int) $session->id,
                'employee_name' => $session->user?->name,
                    'business_date' => $session->business_date?->toDateString(),
                    'status' => $session->status,
                    'box_id' => $session->box_id,
                    'box_name' => $session->box?->name,
                    'currency' => $session->box?->currency,
                    'opening_balance' => round((float) $session->opening_balance, 2),
                'cash_total' => round((float) ($payload['cash_total'] ?? 0), 2),
                'visa_total' => round((float) ($payload['visa_total'] ?? 0), 2),
                'transfer_total' => round((float) ($payload['transfer_total'] ?? 0), 2),
                'debt_total' => round((float) ($payload['debt_total'] ?? 0), 2),
                'expected_closing_balance' => round((float) ($payload['expected_closing_balance'] ?? 0), 2),
                'maintenances_count' => Maintenance::query()
                    ->where('maintenance_daily_session_id', $session->id)
                        ->count(),
                    'can_close' => $session->isOpen() && ! $pendingClosing,
                    'pending_closing_request_id' => $pendingClosing?->id,
                ];
            })
            ->values();
    }

    public function directClose(User $reviewer, int $sessionId, ?int $toBoxId = null, ?string $note = null): MaintenanceDailyClosingRequest
    {
        if (! $this->canReviewClosing($reviewer)) {
            throw ValidationException::withMessages([
                'session' => [__('messages.unauthorized')],
            ]);
        }

        return DB::transaction(function () use ($reviewer, $sessionId, $toBoxId, $note) {
            $session = MaintenanceDailySession::query()
                ->with(['user', 'closingRequests'])
                ->lockForUpdate()
                ->findOrFail($sessionId);

            $pendingClosing = $session->closingRequests
                ->where('status', 'pending')
                ->sortByDesc('id')
                ->first();

            if ($pendingClosing) {
                return $this->approveClosing($reviewer, (int) $pendingClosing->id, $toBoxId, $note);
            }

            if (! $session->isOpen()) {
                throw ValidationException::withMessages([
                    'session' => ['صندوق الصيانة ليس مفتوحاً للإغلاق المباشر.'],
                ]);
            }

            $box = Box::lockForUpdate()->findOrFail($session->box_id);
            $cashCounts = $this->buildClosingCashCounts($session, $box, $note);

            $session->update([
                'status' => config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
                'closing_balance' => round((float) $box->total, 2),
                'closing_requested_at' => now(),
                'closing_requested_by_user_id' => $reviewer->id,
                'closing_request_note' => $note ? trim($note) : null,
            ]);

            $closingRequest = MaintenanceDailyClosingRequest::create([
                'session_id' => $session->id,
                'requested_by_user_id' => $reviewer->id,
                'requested_at' => now(),
                'status' => 'pending',
                'maintenances_count' => Maintenance::query()
                    ->where('maintenance_daily_session_id', $session->id)
                    ->count(),
                'cash_counts' => $cashCounts,
            ]);

            return $this->approveClosing($reviewer, (int) $closingRequest->id, $toBoxId, $note);
        });
    }

    public function approveClosing(User $reviewer, int $requestId, ?int $toBoxId = null, ?string $note = null): MaintenanceDailyClosingRequest
    {
        return DB::transaction(function () use ($reviewer, $requestId, $toBoxId, $note) {
            $closingRequest = MaintenanceDailyClosingRequest::query()
                ->with('session')
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (! $closingRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['طلب إغلاق صندوق الصيانة غير معلق.'],
                ]);
            }

            $session = $closingRequest->session;

            if (! $session->isClosingRequested()) {
                throw ValidationException::withMessages([
                    'session' => ['طلب إغلاق صندوق الصيانة غير معلق.'],
                ]);
            }

            $box = Box::lockForUpdate()->find($session->box_id);
            $closingBalance = round((float) ($box?->total ?? $session->closing_balance ?? 0), 2);
            $transfer = null;

            if ($closingBalance > 0) {
                if (! $box || ! $toBoxId) {
                    throw ValidationException::withMessages([
                        'to_box_id' => ['يجب اختيار صندوق لترحيل صندوق الصيانة.'],
                    ]);
                }

                if ((int) $box->id === (int) $toBoxId) {
                    throw ValidationException::withMessages([
                        'to_box_id' => ['لا يمكن ترحيل الصندوق إلى نفس صندوق الصيانة اليومي.'],
                    ]);
                }

                $toBox = Box::lockForUpdate()->findOrFail($toBoxId);
                if ($toBox->currency !== $box->currency) {
                    throw ValidationException::withMessages([
                        'to_box_id' => [__('messages.must_be_same_currency')],
                    ]);
                }

                $box->update(['total' => 0]);
                $toBox->update(['total' => round((float) $toBox->total + $closingBalance, 2)]);

                $transferNote = trim('جلسة صيانة #'.$session->id.' | بواسطة: '.$reviewer->name.($note ? ' | '.$note : ''));
                BoxLogs::createTransferLog($box, $toBox, 'ترحيل صندوق صيانة يومي', $closingBalance, $transferNote);
                $transfer = [
                    'from_box_id' => $box->id,
                    'to_box_id' => $toBox->id,
                    'to_box_name' => $toBox->name,
                    'amount' => $closingBalance,
                    'currency' => $box->currency,
                ];
            }

            $closingRequest->update([
                'status' => 'approved',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $note,
                'transfers' => $transfer ? [$transfer] : [],
            ]);

            $session->update([
                'status' => config('maintenance_daily.session_status.closed', 'closed'),
                'closing_balance' => $closingBalance,
                'closed_at' => now(),
                'closed_by_user_id' => $reviewer->id,
                'notes' => trim((string) $session->notes."\nاعتماد إغلاق الصندوق بواسطة: {$reviewer->name}".($note ? " | {$note}" : '').($transfer ? ' | ترحيل: '.json_encode($transfer, JSON_UNESCAPED_UNICODE) : '')),
            ]);

            $this->notifyClosingApproved($session->fresh(['employee.user']), $closingRequest);
            $this->logSessionActivity(
                $session,
                $reviewer,
                'maintenance_daily_closing_approved',
                'اعتماد إغلاق صندوق الصيانة',
                'تم اعتماد إغلاق صندوق الصيانة اليومي',
                [
                    'closing_request_id' => (int) $closingRequest->id,
                    'closing_balance' => $closingBalance,
                    'transfer' => $transfer,
                    'note' => $note,
                ],
                'maintenance_daily_closing_request',
                (int) $closingRequest->id
            );

            return $closingRequest->fresh(['session.user', 'session.box', 'requestedBy', 'reviewedBy']);
        });
    }

    public function rejectClosing(User $reviewer, int $requestId, ?string $note = null): MaintenanceDailyClosingRequest
    {
        return DB::transaction(function () use ($reviewer, $requestId, $note) {
            $closingRequest = MaintenanceDailyClosingRequest::query()
                ->with('session')
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (! $closingRequest->isPending()) {
                throw ValidationException::withMessages([
                    'request' => ['طلب إغلاق صندوق الصيانة غير معلق.'],
                ]);
            }

            $session = $closingRequest->session;

            if (! $session->isClosingRequested()) {
                throw ValidationException::withMessages([
                    'session' => ['طلب إغلاق صندوق الصيانة غير معلق.'],
                ]);
            }

            $session->update([
                'status' => config('maintenance_daily.session_status.open', 'open'),
                'closing_balance' => null,
                'closing_requested_at' => null,
                'closing_requested_by_user_id' => null,
                'closing_request_note' => null,
                'notes' => trim((string) $session->notes."\nرفض طلب الإغلاق بواسطة: {$reviewer->name}".($note ? " | {$note}" : '')),
            ]);

            $closingRequest->update([
                'status' => 'rejected',
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $note,
            ]);

            $this->notifyClosingRejected($session->fresh(['employee.user']), $closingRequest);
            $this->logSessionActivity(
                $session,
                $reviewer,
                'maintenance_daily_closing_rejected',
                'رفض إغلاق صندوق الصيانة',
                'تم رفض طلب إغلاق صندوق الصيانة اليومي',
                [
                    'closing_request_id' => (int) $closingRequest->id,
                    'note' => $note,
                ],
                'maintenance_daily_closing_request',
                (int) $closingRequest->id
            );

            return $closingRequest->fresh(['session.user', 'session.box', 'requestedBy', 'reviewedBy']);
        });
    }

    private function logSessionActivity(
        MaintenanceDailySession $session,
        User $actor,
        string $action,
        string $title,
        ?string $description = null,
        array $metadata = [],
        ?string $subjectType = null,
        ?int $subjectId = null
    ): void {
        $businessDate = $session->business_date instanceof Carbon
            ? $session->business_date->toDateString()
            : (string) $session->business_date;

        app(EmployeeActivityLogger::class)->log(
            $session->employee_id ? (int) $session->employee_id : null,
            $actor,
            'maintenance_daily_session',
            $action,
            $title,
            $description,
            $session,
            null,
            array_filter(array_merge([
                'session_id' => (int) $session->id,
                'business_date' => $businessDate,
                'status' => $session->status,
                'box_id' => $session->box_id ? (int) $session->box_id : null,
            ], $metadata), fn ($value) => $value !== null),
            $subjectType ?? 'maintenance_daily_session',
            $subjectId ?? (int) $session->id
        );
    }

    private function buildClosingCashCounts(
        MaintenanceDailySession $session,
        Box $box,
        ?string $note = null
    ): array {
        $closingBalance = round((float) $box->total, 2);

        return [[
            'currency' => $box->currency,
            'daily_box_id' => (int) $box->id,
            'opening_float' => round((float) $session->opening_balance, 2),
            'sales_collected' => round(max(0, $closingBalance - (float) $session->opening_balance), 2),
            'system_balance' => $closingBalance,
            'physical_count' => $closingBalance,
            'variance' => 0,
            'float_to_keep' => 0,
            'amount_to_transfer' => $closingBalance,
            'employee_note' => $note ? trim($note) : '',
            'variance_alert' => false,
        ]];
    }

    /**
     * @return array{session: MaintenanceDailySession, box: Box, log: MaintenanceDailyBoxLog}
     */
    public function recordPayment(
        Maintenance $maintenance,
        User $user,
        float $amount,
        ?int $instantSaleId = null,
        string $method = 'cash',
        ?string $note = null
    ): array {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'payment_amount' => ['قيمة حركة صندوق الصيانة يجب أن تكون أكبر من صفر.'],
            ]);
        }

        return DB::transaction(function () use ($maintenance, $user, $amount, $instantSaleId, $method, $note) {
            $session = $this->requireOpenSession($user);
            $box = Box::lockForUpdate()->findOrFail($session->box_id);
            $before = round((float) $box->total, 2);
            $affectsCash = $method === 'cash';
            $after = $affectsCash ? round($before + $amount, 2) : $before;

            if ($affectsCash) {
                $box->total = $after;
                $box->save();
            }

            $log = MaintenanceDailyBoxLog::create([
                'session_id' => $session->id,
                'box_id' => $box->id,
                'maintenance_id' => $maintenance->id,
                'instant_sale_id' => $instantSaleId,
                'user_id' => $user->id,
                'actor_name' => $user->name,
                'type' => 'add',
                'payment_method' => $method,
                'affects_cash_balance' => $affectsCash,
                'amount' => round($amount, 2),
                'box_balance_before' => $before,
                'box_balance_after' => $after,
                'description' => $this->paymentDescription($method, $maintenance),
                'note' => trim('صيانة #'.$maintenance->id.' | المستخدم: '.$user->name.($note ? ' | '.$note : '')),
            ]);

            return [
                'session' => $session->fresh(['box']),
                'box' => $box,
                'log' => $log,
            ];
        });
    }

    public function recordDebt(
        Maintenance $maintenance,
        User $user,
        float $amount,
        ?int $instantSaleId = null,
        ?string $note = null
    ): MaintenanceDailyBoxLog {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'remaining_amount' => ['قيمة دين الصيانة يجب أن تكون أكبر من صفر.'],
            ]);
        }

        return DB::transaction(function () use ($maintenance, $user, $amount, $instantSaleId, $note) {
            $session = $this->requireOpenSession($user);
            $box = Box::lockForUpdate()->findOrFail($session->box_id);
            $balance = round((float) $box->total, 2);

            return MaintenanceDailyBoxLog::create([
                'session_id' => $session->id,
                'box_id' => $box->id,
                'maintenance_id' => $maintenance->id,
                'instant_sale_id' => $instantSaleId,
                'user_id' => $user->id,
                'actor_name' => $user->name,
                'type' => 'debt',
                'payment_method' => 'debt',
                'affects_cash_balance' => false,
                'amount' => round($amount, 2),
                'box_balance_before' => $balance,
                'box_balance_after' => $balance,
                'description' => 'دين متبقي صيانة #'.$maintenance->id,
                'note' => trim('صيانة #'.$maintenance->id.' | المستخدم: '.$user->name.($note ? ' | '.$note : '')),
            ]);
        });
    }

    public function closeExpiredSessions(?Carbon $at = null): int
    {
        $at = $at ?? now();
        $today = $this->businessDate($at)->toDateString();
        $closed = 0;

        MaintenanceDailySession::query()
            ->whereIn('status', [
                config('maintenance_daily.session_status.open', 'open'),
                config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
            ])
            ->whereDate('business_date', '<', $today)
            ->with('box')
            ->chunkById(50, function ($sessions) use (&$closed, $at) {
                foreach ($sessions as $session) {
                    $session->update([
                        'status' => config('maintenance_daily.session_status.closed', 'closed'),
                        'closing_balance' => round((float) ($session->box?->total ?? 0), 2),
                        'closed_at' => $at,
                        'notes' => trim((string) $session->notes."\nإغلاق تلقائي عند منتصف الليل."),
                    ]);
                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(?string $date = null, ?User $user = null): array
    {
        $businessDate = $date
            ? Carbon::parse($date)->toDateString()
            : $this->businessDate()->toDateString();

        $owner = $user ? $this->resolveOwner($user) : null;
        $globalOpen = $user ? $this->findGlobalOpenSession() : null;
        $blocking = $user && $date === null
            ? $this->findBlockingSession($user)
            : null;

        if ($date === null) {
            $session = $blocking
                ?? ($user ? $this->currentSession($user) : null)
                ?? $globalOpen;
            $session?->loadMissing([
                'box:id,name,total,currency,type',
                'user:id,name',
                'closingRequestedBy:id,name',
                'closingRequests',
            ]);
        } else {
            $sessionQuery = MaintenanceDailySession::query()
                ->with(['box:id,name,total,currency,type', 'user:id,name', 'closingRequestedBy:id,name', 'closingRequests'])
                ->whereDate('business_date', $businessDate);

            if ($user) {
                $sessionQuery->where('user_id', $owner['user_id']);
            }

            $session = $sessionQuery->orderByDesc('id')->first();
        }

        $box = $session?->box ?: ($user ? $this->ensureBox($user) : null);
        $pendingClosing = $session
            ? $session->closingRequests->firstWhere('status', 'pending')
            : null;
        $blockedByOther = $user
            && $globalOpen
            && (int) $globalOpen->user_id !== (int) $owner['user_id'];
        $todaySessionExists = $user
            ? MaintenanceDailySession::query()
                ->where('user_id', $owner['user_id'])
                ->whereDate('business_date', $this->businessDate())
                ->whereIn('status', [
                    config('maintenance_daily.session_status.open', 'open'),
                    config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
                ])
                ->exists()
            : false;
        $isPreviousDayOpen = $session
            && $session->business_date->toDateString() < $this->businessDate()->toDateString()
            && in_array($session->status, [
                config('maintenance_daily.session_status.open', 'open'),
                config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
            ], true);

        $logs = $session
            ? MaintenanceDailyBoxLog::query()
                ->where('session_id', $session->id)
                ->with(['maintenance:id,customer_id,seller_id', 'user:id,name'])
                ->with(['instantSale:id,serial_number'])
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (MaintenanceDailyBoxLog $log) => [
                    'id' => $log->id,
                    'maintenance_id' => $log->maintenance_id,
                    'instant_sale_id' => $log->instant_sale_id,
                    'invoice_number' => $log->instantSale?->serial_number,
                    'instant_sale_serial' => $log->instantSale?->serial_number,
                    'user_id' => $log->user_id,
                    'actor_name' => $log->actor_name ?? $log->user?->name,
                    'type' => $log->type,
                    'payment_method' => $log->payment_method,
                    'affects_cash_balance' => (bool) ($log->affects_cash_balance ?? true),
                    'amount' => round((float) $log->amount, 2),
                    'box_balance_before' => round((float) $log->box_balance_before, 2),
                    'box_balance_after' => round((float) $log->box_balance_after, 2),
                    'description' => $log->description,
                    'note' => $log->note,
                    'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
                ])
                ->values()
            : collect();

        $cashTotal = round((float) $logs
            ->where('affects_cash_balance', true)
            ->filter(fn ($log) => in_array($log['payment_method'] ?? null, ['cash', null], true))
            ->sum('amount'), 2);
        $visaTotal = round((float) $logs
            ->where('payment_method', 'visa')
            ->sum('amount'), 2);
        $transferTotal = round((float) $logs
            ->where('payment_method', 'bank_transfer')
            ->sum('amount'), 2);
        $debtTotal = round((float) ($session
            ? Maintenance::query()
                ->where('maintenance_daily_session_id', $session->id)
                ->where('status', 'delivered')
                ->get(['invoice_total', 'paid_amount'])
                ->sum(fn (Maintenance $maintenance) => max(
                    0,
                    round((float) $maintenance->invoice_total - (float) $maintenance->paid_amount, 2)
                ))
            : 0), 2);
        $expectedClosingBalance = round((float) ($session?->opening_balance ?? 0) + $cashTotal, 2);

        return [
            'session' => $session ? [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'employee_name' => $session->user?->name,
                'business_date' => $session->business_date->toDateString(),
                'status' => $session->status,
                'is_blocking_previous_day' => (bool) $isPreviousDayOpen,
                'previous_day_warning' => (bool) $isPreviousDayOpen,
                'previous_day_owner_name' => $isPreviousDayOpen
                    ? ($session->user?->name ?? null)
                    : null,
                'previous_day_business_date' => $isPreviousDayOpen
                    ? $session->business_date->toDateString()
                    : null,
                'opening_balance' => round((float) $session->opening_balance, 2),
                'closing_balance' => $session->closing_balance !== null
                    ? round((float) $session->closing_balance, 2)
                    : null,
                'expected_closing_balance' => $expectedClosingBalance,
                'opened_at' => optional($session->opened_at)->format('Y-m-d H:i:s'),
                'closing_requested_at' => optional($session->closing_requested_at)->format('Y-m-d H:i:s'),
                'closing_requested_by_name' => $session->closingRequestedBy?->name,
                'closing_request_note' => $session->closing_request_note,
                'closed_at' => optional($session->closed_at)->format('Y-m-d H:i:s'),
                'can_request_closing' => $session->isOpen() && ! $pendingClosing,
                'allows_payments' => $session->isOpen() && ! $pendingClosing,
            ] : null,
            'can_request_open' => $user
                && ! $blocking
                && ! $blockedByOther
                && ! $todaySessionExists,
            'blocked_by_other_session' => (bool) $blockedByOther,
            'blocked_by_employee_name' => $blockedByOther
                ? ($globalOpen?->user?->name ?? null)
                : null,
            'can_finalize_closing' => $user ? $this->canReviewClosing($user) : false,
            'pending_closing_request_id' => $pendingClosing?->id,
            'box' => $box ? [
                'id' => $box->id,
                'name' => $box->name,
                'currency' => $box->currency,
                'total' => round((float) $box->total, 2),
            ] : null,
            'logs' => $logs,
            'logs_total' => round((float) $logs->sum('amount'), 2),
            'cash_total' => $cashTotal,
            'visa_total' => $visaTotal,
            'transfer_total' => $transferTotal,
            'debt_total' => $debtTotal,
            'non_cash_total' => round((float) $logs
                ->where('affects_cash_balance', false)
                ->sum('amount'), 2),
            'expected_closing_balance' => $expectedClosingBalance,
            'config' => [
                'open_time' => config('maintenance_daily.open_time', '08:00'),
                'close_time' => config('maintenance_daily.close_time', '00:00'),
            ],
        ];
    }

    private function paymentDescription(string $method, Maintenance $maintenance): string
    {
        return match ($method) {
            'visa' => 'قبض فيزا صيانة #'.$maintenance->id,
            'bank_transfer' => 'قبض حوالة صيانة #'.$maintenance->id,
            default => 'قبض كاش صيانة #'.$maintenance->id,
        };
    }

    public function formatClosingRequest(MaintenanceDailyClosingRequest $request): array
    {
        $request->loadMissing(['session.user', 'session.box', 'requestedBy', 'reviewedBy']);
        $session = $request->session;
        $payload = $this->payload($session->business_date?->toDateString(), $session->user);
        $sessionPayload = $payload['session'] ?? [];
        $cashCounts = $request->cash_counts ?? [];
        $firstCount = $cashCounts[0] ?? [];

        return [
            'id' => $request->id,
            'session_id' => $session->id,
            'employee_name' => $session->user?->name,
            'business_date' => $session->business_date?->toDateString(),
            'status' => $request->status,
            'opening_balance' => round((float) $session->opening_balance, 2),
            'cash_total' => round((float) ($payload['cash_total'] ?? 0), 2),
            'visa_total' => round((float) ($payload['visa_total'] ?? 0), 2),
            'transfer_total' => round((float) ($payload['transfer_total'] ?? 0), 2),
            'debt_total' => round((float) ($payload['debt_total'] ?? 0), 2),
            'expected_closing_balance' => round((float) ($payload['expected_closing_balance'] ?? 0), 2),
            'amount_to_transfer' => round((float) ($firstCount['amount_to_transfer'] ?? $payload['expected_closing_balance'] ?? 0), 2),
            'cash_counts' => $cashCounts,
            'maintenances_count' => (int) $request->maintenances_count,
            'closing_balance' => $session->closing_balance !== null
                ? round((float) $session->closing_balance, 2)
                : ($sessionPayload['closing_balance'] ?? null),
            'requested_at' => optional($request->requested_at)->format('Y-m-d H:i:s'),
            'requested_by_name' => $request->requestedBy?->name,
            'note' => $firstCount['employee_note'] ?? $session->closing_request_note,
            'box_name' => $session->box?->name,
            'currency' => $session->box?->currency,
            'reviewed_at' => optional($request->reviewed_at)->format('Y-m-d H:i:s'),
            'reviewed_by_name' => $request->reviewedBy?->name,
            'review_notes' => $request->review_notes,
            'transfers' => $request->transfers ?? [],
        ];
    }

    public function canReviewClosing(User $user): bool
    {
        if ($user->type === 'admin') {
            return true;
        }

        $permission = config('sales_daily.permissions.daily_close_review');
        if ($user->type !== 'employee' || ! $user->employee || ! $permission) {
            return false;
        }

        return $user->employee->permissions()
            ->whereHas('permission', fn ($q) => $q->where('name_en', $permission))
            ->exists();
    }

    private function notifyClosingRequested(
        MaintenanceDailySession $session,
        MaintenanceDailyClosingRequest $request,
        User $actor
    ): void {
        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            $employeeName = $session->user?->name ?? 'موظف';
            app(AdminNotificationService::class)->create(
                AdminNotificationService::TYPE_MAINTENANCE_DAILY_CLOSING_REQUEST,
                'طلب إغلاق صندوق صيانة',
                "{$employeeName} أرسل طلب إغلاق صندوق الصيانة اليومي.",
                [
                    'closing_request_id' => (string) $request->id,
                    'session_id' => (string) $session->id,
                    'business_date' => $session->business_date?->toDateString() ?? '',
                    'actor_user_id' => (string) $actor->id,
                ],
                $session->employee_id,
                'maintenance_daily_closing_request',
                (int) $request->id
            );
        } finally {
            App::setLocale($previous);
        }
    }

    private function notifyClosingApproved(
        MaintenanceDailySession $session,
        MaintenanceDailyClosingRequest $request
    ): void {
        $employee = $session->employee;
        if (! $employee) {
            return;
        }

        app(EmployeeNotificationService::class)->create(
            $employee,
            EmployeeNotificationService::TYPE_MAINTENANCE_DAILY_CLOSING_APPROVED,
            'تم اعتماد إغلاق صندوق الصيانة',
            'تم اعتماد طلب إغلاق صندوق الصيانة اليومي.',
            [
                'closing_request_id' => (string) $request->id,
                'session_id' => (string) $session->id,
                'business_date' => $session->business_date?->toDateString() ?? '',
            ],
            'maintenance_daily_closing_request',
            (int) $request->id
        );
    }

    private function notifyClosingRejected(
        MaintenanceDailySession $session,
        MaintenanceDailyClosingRequest $request
    ): void {
        $employee = $session->employee;
        if (! $employee) {
            return;
        }

        app(EmployeeNotificationService::class)->create(
            $employee,
            EmployeeNotificationService::TYPE_MAINTENANCE_DAILY_CLOSING_REJECTED,
            'تم رفض إغلاق صندوق الصيانة',
            'تم رفض طلب إغلاق صندوق الصيانة اليومي. يمكنك متابعة العمل على الصندوق.',
            [
                'closing_request_id' => (string) $request->id,
                'session_id' => (string) $session->id,
                'business_date' => $session->business_date?->toDateString() ?? '',
            ],
            'maintenance_daily_closing_request',
            (int) $request->id
        );
    }
}
