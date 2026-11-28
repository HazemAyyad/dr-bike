<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\EmployeeTasks\EmployeeTaskPauseService;
use Illuminate\Http\Request;

class EmployeeTaskPauseController extends Controller
{
    public function update(Request $request, EmployeeTaskPauseService $service)
    {
        $data = $request->validate([
            'employee_task_id' => 'required_without:occurrence_id|nullable|integer|exists:employee_tasks,id',
            'occurrence_id' => 'nullable|integer|exists:employee_task_occurrences,id',
            'is_paused' => 'required|boolean',
        ]);

        $service->setPaused($data['employee_task_id'] ?? null, $data['occurrence_id'] ?? null, $request->boolean('is_paused'));

        return response()->json(['status' => 'success', 'is_paused' => $request->boolean('is_paused')]);
    }
}
