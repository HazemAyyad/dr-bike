<?php

namespace App\Http\Controllers\API\Employees;

use App\Http\Controllers\Controller;
use App\Services\EmployeePerformanceService;
use Illuminate\Http\Request;

class EmployeePerformanceController extends Controller
{
    public function show(Request $request, EmployeePerformanceService $service)
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:weekly,monthly'],
        ]);
        $employee = $request->user()->employee;
        abort_unless($employee, 403);

        return response()->json([
            'status' => 'success',
            'performance' => $service->report($employee, $data['period'] ?? 'monthly'),
        ]);
    }
}
