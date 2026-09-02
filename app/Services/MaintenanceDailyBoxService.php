<?php

namespace App\Services;

use App\Http\Controllers\API\BoxLogs;
use App\Models\AdminNotification;
use App\Models\Box;
use App\Models\EmployeeDetail;
use App\Models\EmployeeNotification;
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
            $globalSession = $this->findGlobalOpenSession();
            if ($globalSession?->isOpen()) {
                return $globalSession->loadMissing(['box:id,name,total,currency,type']);
            }
        }

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

    public function requestClosing(User $user, ?string $note = null, ?Carbon $at = null, ?array $closingInput = null): MaintenanceDailyClosingRequest
    {
        $at = $at ?? now();

        return DB::transaction(function () use ($user, $note, $at, $closingInput) {
            $session = $this->currentSession($user, $at);
            if (! $session || ! $session->isOpen() || (int) $session->user_id !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'session' => ['فقط صاحب صندوق الصيانة يستطيع طلب إغلاقه.'],
                ]);
            }
            $box = Box::lockForUpdate()->findOrFail($session->box_id);
            $pending = $session->closingRequests()->where('status', 'pending')->exists();
            if ($pending) {
                throw ValidationException::withMessages([
                    'session' => ['يوجد طلب إغلاق معلق لصندوق الصيانة.'],
                ]);
            }

            $cashCounts = $this->buildClosingCashCounts($session, $box, $note, $closingInput);

            $session->update([
                'status' => config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
                'closing_balance' => round((float) ($cashCounts[0]['physical_count'] ?? $box->total), 2),
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

    /**
     * @param  array<string, mixed>  $filters
     * @return array{sessions: \Illuminate\Support\Collection<int, array<string, mixed>>, pagination: array<string, int|null>}
     */
    public function listSessions(User $viewer, array $filters = []): array
    {
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = MaintenanceDailySession::query()
            ->with(['box:id,name,total,currency,type', 'user:id,name', 'employee.user'])
            ->orderByDesc('business_date')
            ->orderByDesc('id');

        if (! $this->canReviewClosing($viewer)) {
            $query->where('user_id', $viewer->id);
        }

        if (! empty($filters['business_date'])) {
            $query->whereDate('business_date', $filters['business_date']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('business_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('business_date', '<=', $filters['to_date']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $sessions = collect($paginator->items())
            ->map(fn (MaintenanceDailySession $session) => $this->formatSessionSummary($session));

        return [
            'sessions' => $sessions,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSessionDetail(User $viewer, int $sessionId): array
    {
        $session = MaintenanceDailySession::query()
            ->with([
                'box:id,name,total,currency,type',
                'user:id,name',
                'employee.user',
                'closingRequests.requestedBy',
                'closingRequests.reviewedBy',
            ])
            ->findOrFail($sessionId);

        $this->assertCanViewSession($viewer, $session);

        $payload = $this->payload($session->business_date?->toDateString(), $session->user);
        $currencies = $this->currenciesForPayload($payload, $session);
        $maintenanceLog = $this->buildSessionMaintenanceLog($session);
        $closingRequests = $session->closingRequests
            ->sortByDesc('id')
            ->values()
            ->map(fn (MaintenanceDailyClosingRequest $request) => $this->formatClosingRequestForDailyModel($request, $session))
            ->all();
        $pendingClosing = $session->closingRequests
            ->contains(fn (MaintenanceDailyClosingRequest $request) => $request->status === 'pending');

        return [
            'session' => [
                'id' => $session->id,
                'user_id' => $session->user_id,
                'employee_id' => $session->employee_id,
                'employee_name' => $session->user?->name,
                'business_date' => $session->business_date?->toDateString(),
                'status' => $session->status,
                'allows_sales' => $session->isOpen() && ! $pendingClosing,
                'can_request_closing' => $session->isOpen()
                    && ! $pendingClosing
                    && ($this->canReviewClosing($viewer) || (int) $session->user_id === (int) $viewer->id),
                'requires_late_close_reason' => false,
                'opened_at' => $session->opened_at?->toDateTimeString(),
                'closed_at' => $session->closed_at?->toDateTimeString(),
                'closed_on_next_day' => $session->closed_at
                    && $session->business_date
                    && $session->closed_at->toDateString() > $session->business_date->toDateString(),
                'opening_balances' => [
                    ($session->box?->currency ?: config('maintenance_daily.currency', 'شيكل')) => round((float) $session->opening_balance, 2),
                ],
            ],
            'currencies' => $currencies,
            'expected_opening_counts' => $this->expectedOpeningCountsForSession($session),
            'instant_sales_count' => count($maintenanceLog),
            'profit_sales_count' => 0,
            'instant_sales' => $maintenanceLog,
            'profit_sales' => [],
            'sales_orders_count' => 0,
            'sales_orders' => [],
            'closing_requests' => $closingRequests,
            'config' => [
                'variance_alert_threshold' => 50,
                'max_float' => [
                    config('maintenance_daily.currency', 'شيكل') => 0,
                ],
            ],
        ];
    }

    public function assertCanViewSession(User $viewer, MaintenanceDailySession $session): void
    {
        if ($this->canReviewClosing($viewer)) {
            return;
        }

        if ((int) $session->user_id === (int) $viewer->id) {
            return;
        }

        throw ValidationException::withMessages([
            'session' => [__('messages.unauthorized')],
        ]);
    }

    /**
     * @return array{
     *     checked:int,
     *     admin_notified:int,
     *     employee_notified:int,
     *     skipped_admin_duplicate:int,
     *     skipped_employee_duplicate:int,
     *     missing_employee:int,
     *     details:list<array<string, mixed>>
     * }
     */
    public function sendPreviousDayOpenReminders(bool $force = false): array
    {
        $tz = 'Asia/Hebron';
        $now = Carbon::now($tz);
        $today = $now->toDateString();
        $reminderDate = $today;
        $slotMinute = ((int) $now->format('i')) < 30 ? 0 : 30;
        $reminderSlot = $now->copy()
            ->setTime((int) $now->format('H'), $slotMinute, 0)
            ->format('Y-m-d H:i');

        $stats = [
            'checked' => 0,
            'admin_notified' => 0,
            'employee_notified' => 0,
            'skipped_admin_duplicate' => 0,
            'skipped_employee_duplicate' => 0,
            'missing_employee' => 0,
            'details' => [],
        ];

        $sessions = MaintenanceDailySession::query()
            ->with(['user', 'employee.user'])
            ->whereIn('status', [
                config('maintenance_daily.session_status.open', 'open'),
                config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
            ])
            ->whereDate('business_date', '<', $today)
            ->orderBy('business_date')
            ->orderBy('id')
            ->get();

        $previous = App::getLocale();
        App::setLocale('ar');

        try {
            foreach ($sessions as $session) {
                $stats['checked']++;

                $employee = $this->resolveSessionEmployee($session);
                $employee?->loadMissing('user');
                $session->loadMissing('user');

                $employeeName = $session->user?->name
                    ?? $employee?->user?->name
                    ?? 'موظف';
                $businessDate = $session->business_date?->toDateString() ?? '';

                $data = [
                    'session_id' => (string) $session->id,
                    'business_date' => $businessDate,
                    'session_status' => (string) $session->status,
                    'employee_id' => (string) ($session->employee_id ?? $employee?->id ?? ''),
                    'employee_name' => $employeeName,
                    'owner_user_id' => (string) $session->user_id,
                    'reminder_date' => $reminderDate,
                    'reminder_slot' => $reminderSlot,
                    'checked_at' => $now->toIso8601String(),
                ];

                $adminSent = false;
                if (! $force && $this->previousDayAdminReminderExists($session, $reminderSlot)) {
                    $stats['skipped_admin_duplicate']++;
                } else {
                    app(AdminNotificationService::class)->create(
                        AdminNotificationService::TYPE_MAINTENANCE_DAILY_PREVIOUS_DAY_OPEN,
                        'صندوق صيانة غير مغلق',
                        "{$employeeName} لم يغلق صندوق صيانة يوم {$businessDate}. يرجى إغلاق الصندوق.",
                        $data,
                        $session->employee_id ?: $employee?->id,
                        'maintenance_daily_session',
                        (int) $session->id
                    );
                    $adminSent = true;
                    $stats['admin_notified']++;
                }

                $employeeSent = false;
                if (! $employee) {
                    $stats['missing_employee']++;
                } elseif (! $force && $this->previousDayEmployeeReminderExists($employee, $session, $reminderSlot)) {
                    $stats['skipped_employee_duplicate']++;
                } else {
                    app(EmployeeNotificationService::class)->create(
                        $employee,
                        EmployeeNotificationService::TYPE_MAINTENANCE_DAILY_PREVIOUS_DAY_OPEN,
                        'تذكير إغلاق صندوق الصيانة',
                        "صندوق صيانة يوم {$businessDate} ما زال غير مغلق. يرجى إغلاق الصندوق.",
                        $data,
                        'maintenance_daily_session',
                        (int) $session->id
                    );
                    $employeeSent = true;
                    $stats['employee_notified']++;
                }

                $stats['details'][] = [
                    'session_id' => (int) $session->id,
                    'business_date' => $businessDate,
                    'status' => (string) $session->status,
                    'employee_id' => $employee?->id,
                    'employee_name' => $employeeName,
                    'reminder_slot' => $reminderSlot,
                    'admin_sent' => $adminSent,
                    'employee_sent' => $employeeSent,
                ];
            }
        } finally {
            App::setLocale($previous);
        }

        return $stats;
    }

    private function resolveSessionEmployee(MaintenanceDailySession $session): ?EmployeeDetail
    {
        if ($session->employee) {
            return $session->employee;
        }

        if (! $session->user_id) {
            return null;
        }

        return EmployeeDetail::query()
            ->where('user_id', $session->user_id)
            ->first();
    }

    private function previousDayAdminReminderExists(MaintenanceDailySession $session, string $reminderSlot): bool
    {
        return AdminNotification::query()
            ->where('type', AdminNotificationService::TYPE_MAINTENANCE_DAILY_PREVIOUS_DAY_OPEN)
            ->where('related_type', 'maintenance_daily_session')
            ->where('related_id', $session->id)
            ->where('data->reminder_slot', $reminderSlot)
            ->exists();
    }

    private function previousDayEmployeeReminderExists(
        EmployeeDetail $employee,
        MaintenanceDailySession $session,
        string $reminderSlot
    ): bool {
        return EmployeeNotification::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeNotificationService::TYPE_MAINTENANCE_DAILY_PREVIOUS_DAY_OPEN)
            ->where('related_type', 'maintenance_daily_session')
            ->where('related_id', $session->id)
            ->where('data->reminder_slot', $reminderSlot)
            ->exists();
    }

    public function directClose(User $reviewer, int $sessionId, ?int $toBoxId = null, ?string $note = null, ?array $closingInput = null): MaintenanceDailyClosingRequest
    {
        return DB::transaction(function () use ($reviewer, $sessionId, $toBoxId, $note, $closingInput) {
            $session = MaintenanceDailySession::query()
                ->with(['user', 'closingRequests'])
                ->lockForUpdate()
                ->findOrFail($sessionId);

            if ((int) $session->user_id !== (int) $reviewer->id) {
                throw ValidationException::withMessages([
                    'session' => ['فقط صاحب صندوق الصيانة يستطيع إغلاقه.'],
                ]);
            }

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
            $cashCounts = $this->buildClosingCashCounts($session, $box, $note, $closingInput);

            $session->update([
                'status' => config('maintenance_daily.session_status.closing_requested', 'closing_requested'),
                'closing_balance' => round((float) ($cashCounts[0]['physical_count'] ?? $box->total), 2),
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

            $cashCount = collect($closingRequest->cash_counts ?? [])->first() ?: [];
            $box = Box::lockForUpdate()->find($session->box_id);
            $closingBalance = round((float) ($cashCount['physical_count'] ?? $box?->total ?? $session->closing_balance ?? 0), 2);
            $floatToKeep = round((float) ($cashCount['float_to_keep'] ?? 0), 2);
            $amountToTransfer = round((float) ($cashCount['amount_to_transfer'] ?? max(0, $closingBalance - $floatToKeep)), 2);
            $transfer = null;

            if ($amountToTransfer > 0) {
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

                $box->update(['total' => $floatToKeep]);
                $toBox->update(['total' => round((float) $toBox->total + $amountToTransfer, 2)]);

                $transferNote = trim('جلسة صيانة #'.$session->id.' | بواسطة: '.$reviewer->name.($note ? ' | '.$note : ''));
                BoxLogs::createTransferLog($box, $toBox, 'ترحيل صندوق صيانة يومي', $amountToTransfer, $transferNote);
                $transfer = [
                    'from_box_id' => $box->id,
                    'to_box_id' => $toBox->id,
                    'to_box_name' => $toBox->name,
                    'amount' => $amountToTransfer,
                    'float_kept' => $floatToKeep,
                    'currency' => $box->currency,
                ];
            } elseif ($box) {
                $box->update(['total' => $floatToKeep]);
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
        ?string $note = null,
        ?array $input = null
    ): array {
        $closingBalance = round((float) $box->total, 2);
        $physicalCount = round((float) ($input['physical_count'] ?? $closingBalance), 2);
        $floatToKeep = round((float) ($input['float_to_keep'] ?? 0), 2);

        if ($floatToKeep > $physicalCount) {
            throw ValidationException::withMessages([
                'float_to_keep' => ['لا يمكن أن تكون فكة الغد أكبر من المعدود فعلياً.'],
            ]);
        }

        $variance = round($physicalCount - $closingBalance, 2);
        $amountToTransfer = round(max(0, $physicalCount - $floatToKeep), 2);

        return [[
            'currency' => $box->currency,
            'daily_box_id' => (int) $box->id,
            'opening_float' => round((float) $session->opening_balance, 2),
            'sales_collected' => round(max(0, $closingBalance - (float) $session->opening_balance), 2),
            'system_balance' => $closingBalance,
            'physical_count' => $physicalCount,
            'variance' => $variance,
            'float_to_keep' => $floatToKeep,
            'amount_to_transfer' => $amountToTransfer,
            'employee_note' => $note ? trim($note) : '',
            'variance_alert' => abs($variance) > 0.01,
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
            ->sum('amount'), 2);
        $debtTotal = round((float) $logs
            ->where('payment_method', 'debt')
            ->sum('amount'), 2);
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
                'can_request_closing' => $session->isOpen()
                    && ! $pendingClosing
                    && $user
                    && (int) $session->user_id === (int) $owner['user_id'],
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
            'logs_total' => $cashTotal,
            'cash_total' => $cashTotal,
            'visa_total' => 0,
            'transfer_total' => 0,
            'debt_total' => $debtTotal,
            'non_cash_total' => 0,
            'expected_closing_balance' => $expectedClosingBalance,
            'config' => [
                'open_time' => config('maintenance_daily.open_time', '08:00'),
                'close_time' => config('maintenance_daily.close_time', '00:00'),
            ],
        ];
    }

    private function paymentDescription(string $method, Maintenance $maintenance): string
    {
        return 'قبض كاش صيانة #'.$maintenance->id;
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

    /**
     * @return array<string, mixed>
     */
    private function formatSessionSummary(MaintenanceDailySession $session): array
    {
        $session->loadMissing(['box:id,name,total,currency,type', 'user:id,name', 'closingRequests']);
        $payload = $this->payload($session->business_date?->toDateString(), $session->user);
        $maintenanceCount = Maintenance::query()
            ->where('maintenance_daily_session_id', $session->id)
            ->count();
        $pendingClosing = $session->closingRequests
            ->where('status', 'pending')
            ->sortByDesc('id')
            ->first();
        $currencyRows = $this->currenciesForPayload($payload, $session);
        $firstCurrency = $currencyRows[0] ?? [];
        $firstCount = collect($pendingClosing?->cash_counts ?? [])->first() ?: [];

        return [
            'id' => $session->id,
            'session_id' => $session->id,
            'session_type' => 'maintenance',
            'user_id' => $session->user_id,
            'employee_id' => $session->employee_id,
            'employee_name' => $session->user?->name,
            'business_date' => $session->business_date?->toDateString(),
            'status' => $session->status,
            'opening_balance' => round((float) $session->opening_balance, 2),
            'cash_total' => round((float) ($payload['cash_total'] ?? 0), 2),
            'visa_total' => round((float) ($payload['visa_total'] ?? 0), 2),
            'transfer_total' => round((float) ($payload['transfer_total'] ?? 0), 2),
            'debt_total' => round((float) ($payload['debt_total'] ?? 0), 2),
            'expected_closing_balance' => round((float) ($payload['expected_closing_balance'] ?? 0), 2),
            'amount_to_transfer' => round((float) ($firstCount['amount_to_transfer'] ?? $payload['expected_closing_balance'] ?? 0), 2),
            'currency' => (string) ($firstCurrency['currency'] ?? config('maintenance_daily.currency', 'شيكل')),
            'box_name' => (string) ($firstCurrency['daily_box_name'] ?? config('maintenance_daily.box_name', 'صندوق الصيانة اليومي')),
            'opened_at' => $session->opened_at?->toDateTimeString(),
            'closed_at' => $session->closed_at?->toDateTimeString(),
            'closed_on_next_day' => $session->closed_at
                && $session->business_date
                && $session->closed_at->toDateString() > $session->business_date->toDateString(),
            'instant_sales_count' => $maintenanceCount,
            'profit_sales_count' => 0,
            'currencies' => $currencyRows,
            'expected_opening_counts' => $this->expectedOpeningCountsForSession($session),
            'can_close' => $session->isOpen() && ! $pendingClosing,
            'pending_closing_request_id' => $pendingClosing?->id,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currenciesForPayload(array $payload, MaintenanceDailySession $session): array
    {
        $box = $session->box;
        $currency = $box?->currency ?: config('maintenance_daily.currency', 'شيكل');
        $opening = round((float) ($session->opening_balance ?? 0), 2);
        $cash = round((float) ($payload['cash_total'] ?? 0), 2);
        $expectedClosing = round((float) ($payload['expected_closing_balance'] ?? ($opening + $cash)), 2);

        return [[
            'currency' => $currency,
            'daily_box_id' => (int) ($box?->id ?? $session->box_id ?? 0),
            'daily_box_name' => $box?->name ?: config('maintenance_daily.box_name', 'صندوق الصيانة اليومي'),
            'box_balance' => round((float) ($box?->total ?? $expectedClosing), 2),
            'opening_float' => $opening,
            'sales_collected' => $cash,
            'system_balance' => $expectedClosing,
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expectedOpeningCountsForSession(?MaintenanceDailySession $session = null): array
    {
        $currency = config('maintenance_daily.currency', 'شيكل');
        $previousQuery = MaintenanceDailySession::query()
            ->with([
                'user:id,name',
                'closingRequests' => fn ($query) => $query
                    ->where('status', 'approved')
                    ->latest('id')
                    ->limit(1),
            ])
            ->where('status', config('maintenance_daily.session_status.closed', 'closed'));

        if ($session) {
            if ($session->opened_at) {
                $previousQuery->where('closed_at', '<=', $session->opened_at);
            } else {
                $previousQuery->where(function ($query) use ($session) {
                    $query
                        ->whereDate('business_date', '<', $session->business_date)
                        ->orWhere(function ($sameDay) use ($session) {
                            $sameDay
                                ->whereDate('business_date', $session->business_date)
                                ->where('id', '<', $session->id);
                        });
                });
            }
            $previousQuery->where('id', '!=', $session->id);
        }

        $previous = $previousQuery
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->first();
        $previousRequest = $previous?->closingRequests->first();
        $count = collect($previousRequest?->cash_counts ?? [])->first() ?: [];

        return [[
            'currency' => (string) ($count['currency'] ?? $currency),
            'expected_amount' => round((float) ($count['float_to_keep'] ?? 0), 2),
            'previous_daily_box_id' => $count['daily_box_id'] ?? $previous?->box_id,
            'previous_employee_name' => $previous?->user?->name,
            'previous_session_id' => $previous?->id,
            'previous_business_date' => $previous?->business_date?->toDateString(),
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSessionMaintenanceLog(MaintenanceDailySession $session): array
    {
        return MaintenanceDailyBoxLog::query()
            ->where('session_id', $session->id)
            ->with(['maintenance.customer:id,name', 'maintenance.seller:id,name', 'user:id,name', 'instantSale:id,serial_number'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (MaintenanceDailyBoxLog $log) {
                $maintenance = $log->maintenance;
                $partyName = $maintenance?->customer?->name ?: $maintenance?->seller?->name;

                return [
                    'id' => $log->id,
                    'sale_type' => 'maintenance',
                    'label' => trim('دفعة كاش صيانة #'.($log->maintenance_id ?? '-')),
                    'invoice_number' => $log->instantSale?->serial_number
                        ?: ($log->maintenance_id ? 'MNT-'.str_pad((string) $log->maintenance_id, 6, '0', STR_PAD_LEFT) : null),
                    'serial_number' => $log->instantSale?->serial_number,
                    'maintenance_id' => $log->maintenance_id,
                    'maintenance_invoice_number' => $log->maintenance_id
                        ? 'MNT-'.str_pad((string) $log->maintenance_id, 6, '0', STR_PAD_LEFT)
                        : null,
                    'is_package_sale' => false,
                    'is_from_sales_order' => false,
                    'total_cost' => round((float) $log->amount, 2),
                    'paid_amount' => round((float) $log->amount, 2),
                    'remaining_amount' => 0,
                    'quantity' => 1,
                    'status' => 'active',
                    'created_at' => $log->created_at?->toDateTimeString(),
                    'buyer_name' => $partyName,
                    'created_by' => $log->user_id,
                    'created_by_name' => $log->actor_name ?: $log->user?->name,
                    'payment_box_name' => 'cash',
                    'payment_box_value' => round((float) $log->amount, 2),
                    'notes' => $log->note,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatClosingRequestForDailyModel(
        MaintenanceDailyClosingRequest $request,
        ?MaintenanceDailySession $session = null
    ): array {
        $request->loadMissing(['requestedBy', 'reviewedBy', 'session.user', 'session.box']);
        $session = $session ?? $request->session;
        $requestedDate = $request->requested_at?->toDateString();
        $businessDate = $session?->business_date?->toDateString();

        return [
            'id' => $request->id,
            'status' => $request->status,
            'requested_at' => $request->requested_at?->toDateTimeString(),
            'requested_date' => $requestedDate,
            'reviewed_at' => $request->reviewed_at?->toDateTimeString(),
            'reviewed_date' => $request->reviewed_at?->toDateString(),
            'review_notes' => $request->review_notes,
            'requested_by' => $request->requestedBy?->name,
            'reviewed_by' => $request->reviewedBy?->name,
            'instant_sales_count' => $request->maintenances_count,
            'profit_sales_count' => 0,
            'cash_counts' => $request->cash_counts ?? [],
            'transfers' => $request->transfers ?? [],
            'is_late_close' => $businessDate && $requestedDate ? $requestedDate > $businessDate : false,
            'late_close_reason' => null,
            'business_date' => $businessDate,
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
