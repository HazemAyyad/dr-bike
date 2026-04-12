<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Box;
use App\Models\BoxLog;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Doctrine\DBAL\Query\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BoxLogs extends Controller
{

    static public function createTransferLog(Box $fromBox , Box $toBox ,$description, $value){


        BoxLog::create([
            'from_box_id' => $fromBox->id,
            'to_box_id' => $toBox->id,
            'description' => $description,
            'value' => $value,
            'type' => 'transfer',
        ]);
    

}


    static public function createBoxLog(Box $box ,$description, $type,$value){


        BoxLog::create([
            'box_id' => $box->id,
            'description' => $description,
            'value' => $value,

            'type' => $type,
        ]);
    

}

    public function allBoxLogs(){
        try{
            $logs = BoxLog::with('fromBox:id,name,total')
            ->with('toBox:id,name,total')
            ->with('box:id,name,total')->get();
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
                    ->with(['fromBox:id,name,total', 'toBox:id,name,total', 'box:id,name,total'])
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