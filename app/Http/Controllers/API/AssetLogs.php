<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetLogsResource;
use App\Models\Asset;
use App\Models\AssetLog;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
class AssetLogs extends Controller
{
    public function getAllLogs(){
        try{
        $logs = AssetLog::all();
        $formatted =  AssetLogsResource::collection($logs);


        return response()->json([
            'status'=>'success',
            'asset_logs' => $formatted,
        ],200);
    }

            catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }
    public function getAssetLogs(Request $request){
      try{
        $request->validate(['asset_id'=>'required|exists:assets,id']);

        $asset = Asset::findOrFail($request->asset_id);
        $logs = $asset->logs;
        $formatted = AssetLogsResource::collection($logs);

        return response()->json([
            'status'=>'success',
            'asset_logs' => $formatted,
        ],200);
    }


    catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }

     catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.asset_not_found'),
            ], 200);
        }
     catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }

  }


     public function getAllLogsReport(){
        try{


        $logs = AssetLog::all();

        $reportHtml = view('pdf.asset-logs', [
            'logs' => $logs,
        ])->render();

        $arabic = new Arabic();
        $positions = $arabic->arIdentify($reportHtml);

        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(
                substr($reportHtml, $positions[$i - 1], $positions[$i] - $positions[$i - 1])
            );
            $reportHtml = substr_replace($reportHtml, $utf8ar, $positions[$i - 1], $positions[$i] - $positions[$i - 1]);
        }
        $pdf = Pdf::loadHTML($reportHtml);

        return $pdf->download('asset-logs.pdf');

    }

            catch (QueryException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong')
            ], 200);
        }
    }
}
