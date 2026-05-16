<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CronJobLog;
use Illuminate\Http\Request;

class CronJobLogController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = CronJobLog::query()->orderByDesc('started_at');

            if ($request->filled('status')) {
                $query->where('status', $request->string('status'));
            }

            if ($request->filled('job_name')) {
                $query->where('job_name', 'like', '%'.$request->string('job_name').'%');
            }

            if ($request->filled('date_from')) {
                $query->whereDate('started_at', '>=', $request->date('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->whereDate('started_at', '<=', $request->date('date_to'));
            }

            $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
            $paginator = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'cron_job_logs' => $paginator,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }

    public function show(int $id)
    {
        try {
            $log = CronJobLog::query()->findOrFail($id);

            return response()->json([
                'status' => 'success',
                'cron_job_log' => $log,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.retrieve_data_error'),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.something_wrong'),
            ], 200);
        }
    }
}
