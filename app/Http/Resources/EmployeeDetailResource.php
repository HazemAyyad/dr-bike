<?php

namespace App\Http\Resources;

use App\Models\EmployeeAttendance;
use App\Models\FingerprintRawLog;
use App\Services\EmployeeAttendanceCheckoutService;
use App\Support\FingerprintAttendanceLogFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user?->name ?? '',
            'email' => $this->user?->email ?? '',
            'phone' => $this->user?->phone ?? 'no phone number',
            'sub_phone' => $this->user?->sub_phone ?? 'no sub phone number',

            'hour_work_price' => $this->hour_work_price,
            'overtime_work_price' => $this->overtime_work_price,
            'number_of_work_hours' => $this->number_of_work_hours,
            'start_work_time' => $this->start_work_time,
            'end_work_time' => $this->end_work_time,

            'fingerprint_enabled' => (bool) ($this->fingerprint_enabled ?? false),
            'device_user_id' => $this->device_user_id ? (string) $this->device_user_id : null,
            'last_fingerprint_scan_at' => $this->formatFingerprintTimestamp(
                $this->device_user_id
                    ? FingerprintAttendanceLogFilter::apply(
                        FingerprintRawLog::query()->where('device_user_id', (string) $this->device_user_id)
                    )
                        ->orderByDesc('scan_time')
                        ->value('scan_time')
                    : null
            ),
            'last_fingerprint_attendance_at' => $this->lastFingerprintAttendanceAtIso(),

            'currently_in_today' => app(EmployeeAttendanceCheckoutService::class)
                ->isCurrentlyIn((int) $this->id),

            'weekly_days_off' => collect(is_array($this->weekly_days_off) ? $this->weekly_days_off : [])
                ->filter(fn ($v) => is_string($v))
                ->map(fn ($v) => strtolower(trim($v)))
                ->unique()
                ->values()
                ->all(),

            'employee_img' => $this->employee_img
                ? collect($this->employee_img)->map(fn($img) => 'public/EmployeeImages/'.$img)->toArray()
                : 'no images',

            'document_img' => $this->document_img
                ? collect($this->document_img)->map(fn($doc) => 'public/EmployeeDocumetImages/'.$doc)->toArray()
                : 'no document images',

        ];
    }

    protected function formatFingerprintTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable $e) {
            return is_string($value) ? $value : null;
        }
    }

    protected function lastFingerprintAttendanceAtIso(): ?string
    {
        if ($this->device_user_id) {
            $processed = FingerprintAttendanceLogFilter::apply(
                FingerprintRawLog::query()
                    ->where('device_user_id', (string) $this->device_user_id)
                    ->where('processing_status', 'processed')
            )
                ->orderByDesc('scan_time')
                ->value('scan_time');

            if ($processed) {
                return $this->formatFingerprintTimestamp($processed);
            }
        }

        $attendance = EmployeeAttendance::query()
            ->where('employee_id', $this->id)
            ->where('source', 'fingerprint')
            ->orderByDesc('date')
            ->first(['date', 'arrived_at', 'left_at']);

        if (! $attendance || ! $attendance->arrived_at) {
            return null;
        }

        $time = $attendance->left_at ?: $attendance->arrived_at;
        $dateStr = $attendance->date instanceof Carbon
            ? $attendance->date->format('Y-m-d')
            : (string) $attendance->date;

        return $this->formatFingerprintTimestamp("{$dateStr} {$time}");
    }
}
