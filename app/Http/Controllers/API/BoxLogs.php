<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\BoxLog;
use App\Models\MaintenanceDailyBoxLog;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Doctrine\DBAL\Query\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BoxLogs extends Controller
{

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

        BoxLog::create($payload);
    }

    public function allBoxLogs(){
        try{
            $boxLogs = BoxLog::with('fromBox:id,name,total,type')
            ->with('toBox:id,name,total,type')
            ->with('box:id,name,total,type')
            ->get()
            ->map(fn (BoxLog $log) => $log->toArray());

            $maintenanceLogs = MaintenanceDailyBoxLog::query()
                ->with('box:id,name,total,type')
                ->get()
                ->map(fn (MaintenanceDailyBoxLog $log) => [
                    'id' => $log->id,
                    'from_box_id' => null,
                    'to_box_id' => null,
                    'box_id' => $log->box_id,
                    'description' => $log->description,
                    'note' => $log->note,
                    'value' => round((float) $log->amount, 2),
                    'type' => $log->type,
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
    public function boxLogsReport(Request $request){
        try{

            $request->validate([
                
                'box_id'=>'required|integer|exists:boxes,id',
                'from_date' => ['required', 'date'],
                'to_date'   => ['required', 'date', 'after_or_equal:from_date'],

            
            ]);
            $box = Box::findOrFail($request->box_id);
            $logs = BoxLog::where(function ($q) use ($box) {
                        $q->where('box_id', $box->id)
                        ->orWhere('to_box_id', $box->id)
                        ->orWhere('from_box_id', $box->id);
                    })
                    ->when($request->from_date, function ($q) use ($request) {
                        $q->whereDate('created_at', '>=', $request->from_date);
                    })
                    ->when($request->to_date, function ($q) use ($request) {
                        $q->whereDate('created_at', '<=', $request->to_date);
                    })
                    ->with(['fromBox:id,name,total,type', 'toBox:id,name,total,type', 'box:id,name,total,type'])
                    ->get();



            $reportHtml = view('pdf.boxlogs-report', [
                'box' => $box,
                'logs' => $logs,
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

            return $pdf->download('boxlogs-report.pdf');

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
