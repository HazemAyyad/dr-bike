<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\MaintenanceDailyBoxLog;
use App\Services\BoxReportService;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Doctrine\DBAL\Query\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BoxLogs extends Controller
{
    private function actorVisibleBoxIds(Request $request): ?array
    {
        $user = $request->user();
        if (! $user || $user->type === 'admin' || ! Schema::hasTable('employee_visible_boxes')) {
            return null;
        }

        $employee = $user->employee;
        if (! $employee) {
            return [];
        }

        return $employee->visibleBoxes()
            ->pluck('boxes.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function actorCanAccessBox(Request $request, int $boxId): bool
    {
        $visibleIds = $this->actorVisibleBoxIds($request);
        return $visibleIds === null || in_array($boxId, $visibleIds, true);
    }

    private function actorCanReportBox(Request $request, Box $box): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        if ($user->type === 'admin') {
            return true;
        }

        $dailyTypes = [
            config('sales_daily.box_type', 'daily_sales'),
            config('maintenance_daily.box_type', 'daily_maintenance'),
            config('sales_orders.daily_box.type', 'daily_sales_orders'),
        ];
        $requiredPermission = in_array($box->type, $dailyTypes, true)
            ? 'Daily Boxes'
            : 'Boxes Section';

        $hasPermission = (bool) $user->employee?->permissions()
            ->whereHas('permission', fn ($q) => $q->where('name_en', $requiredPermission))
            ->exists();

        if (! $hasPermission) {
            return false;
        }

        return in_array($box->type, $dailyTypes, true)
            || $this->actorCanAccessBox($request, (int) $box->id);
    }

    static public function createTransferLog(Box $fromBox , Box $toBox ,$description, $value, ?string $note = null){


        BoxLog::create([
            'from_box_id' => $fromBox->id,
            'to_box_id' => $toBox->id,
            'description' => $description,
            'note' => $note,
            'value' => $value,
            'type' => 'transfer',
        ]);
    

}


    static public function createBoxLog(Box $box, $description, $type, $value, ?string $note = null)
    {
        $payload = [
            'box_id' => $box->id,
            'description' => $description,
            'note' => $note,
        ];

        $numericValue = (float) $value;

        if (Schema::hasColumn('box_logs', 'value')) {
            $payload['value'] = $numericValue;
        }

        if (Schema::hasColumn('box_logs', 'type')) {
            $payload['type'] = $type;
        }

        // Legacy column from original migration
        if (Schema::hasColumn('box_logs', 'transfered_balance')) {
            $payload['transfered_balance'] = abs($numericValue);
        }

        return BoxLog::create($payload);
    }

    public function allBoxLogs(){
        try{
            $visibleIds = $this->actorVisibleBoxIds(request());

            $boxLogs = BoxLog::query()
            ->when($visibleIds !== null, function ($query) use ($visibleIds) {
                $query->where(function ($q) use ($visibleIds) {
                    $q->whereIn('box_id', $visibleIds)
                        ->orWhereIn('to_box_id', $visibleIds)
                        ->orWhereIn('from_box_id', $visibleIds);
                });
            })
            ->with('fromBox:id,name,total,type')
            ->with('toBox:id,name,total,type')
            ->with('box:id,name,total,type')
            ->get()
            ->map(fn (BoxLog $log) => $log->toArray());

            $maintenanceLogs = MaintenanceDailyBoxLog::query()
                ->when($visibleIds !== null, fn ($query) => $query->whereIn('box_id', $visibleIds))
                ->with(['box:id,name,total,type', 'instantSale:id,serial_number'])
                ->get()
                ->map(fn (MaintenanceDailyBoxLog $log) => [
                    'id' => $log->id,
                    'from_box_id' => null,
                    'to_box_id' => null,
                    'box_id' => $log->box_id,
                    'maintenance_id' => $log->maintenance_id,
                    'instant_sale_id' => $log->instant_sale_id,
                    'invoice_number' => $log->instantSale?->serial_number,
                    'instant_sale_serial' => $log->instantSale?->serial_number,
                    'description' => $log->description,
                    'note' => $log->note,
                    'value' => round((float) $log->amount, 2),
                    'box_balance_before' => round((float) $log->box_balance_before, 2),
                    'box_balance_after' => round((float) $log->box_balance_after, 2),
                    'type' => $log->type,
                    'payment_method' => $log->payment_method,
                    'affects_cash_balance' => (bool) ($log->affects_cash_balance ?? true),
                    'created_at' => optional($log->created_at)->toJSON(),
                    'updated_at' => optional($log->updated_at)->toJSON(),
                    'from_box' => null,
                    'to_box' => null,
                    'box' => $log->box?->toArray(),
                ]);

            $logs = $boxLogs
                ->concat($maintenanceLogs)
                ->sortByDesc('created_at')
                ->values();

            return response()->json([
                'status' => 'success',
                'box_logs' => $logs
            ],200);
        }
        catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }

    // box logs report
    public function boxLogsData(Request $request, BoxReportService $reportService)
    {
        $filters = $reportService->validate($request);
        $box = Box::findOrFail($filters['box_id']);
        if (! $this->actorCanReportBox($request, $box)) {
            return response()->json(['status' => 'error', 'message' => __('messages.box_not_found')], 403);
        }

        $data = ! empty($filters['all'])
            ? $reportService->report($box, $filters)
            : $reportService->paginate($box, $filters);

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function boxLogsReport(Request $request, BoxReportService $reportService){
        try{

            $filters = $reportService->validate($request);
            $box = Box::findOrFail($filters['box_id']);
            if (! $this->actorCanReportBox($request, $box)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => __('messages.box_not_found')
                ], 403);
            }
            $report = $reportService->report($box, $filters);



            $reportHtml = view('pdf.boxlogs-report', [
                'box' => $box,
                'logs' => $report['logs'],
                'summary' => $report['summary'],
                'filters' => $filters,
            ])->render();

            // 🔹 Fix Arabic text
            $arabic = new Arabic();
            $positions = $arabic->arIdentify($reportHtml);

            for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
                $utf8ar = $arabic->utf8Glyphs(
                    substr($reportHtml, $positions[$i - 1], $positions[$i] - $positions[$i - 1])
                );
                $reportHtml = substr_replace($reportHtml, $utf8ar, $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
            }

            // 🔹 Load fixed HTML into PDF
            $pdf = Pdf::loadHTML($reportHtml);

            return $pdf->setPaper('a4', 'landscape')->download('box-report-'.$box->id.'.pdf');

        }
        catch (ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.validation_failed'),
                'errors'  => $e->errors()
            ], 200); }
                catch (QueryException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }
}
