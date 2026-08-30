<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDetail;
use App\Models\EmployeeSignature;
use App\Services\EmployeeSignatureService;
use Illuminate\Http\Request;

class EmployeeSignatureController extends Controller
{
    public function index(Request $request)
    {
        $employee = $this->employee($request);
        $signatures = EmployeeSignature::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('is_default')
            ->latest('id')
            ->get()
            ->map(fn (EmployeeSignature $signature) => $this->payload($signature));

        return response()->json(['status' => 'success', 'signatures' => $signatures]);
    }

    public function store(Request $request, EmployeeSignatureService $service)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'source' => ['required', 'in:manual,camera,upload'],
            'signature' => ['required', 'string', 'max:14000000'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        $signature = $service->create(
            $this->employee($request),
            $data['name'],
            $data['source'],
            $data['signature'],
            (bool) ($data['is_default'] ?? false)
        );

        return response()->json([
            'status' => 'success',
            'message' => 'تم حفظ التوقيع واعتماده بنجاح.',
            'signature' => $this->payload($signature),
        ]);
    }

    public function update(
        Request $request,
        EmployeeSignature $signature,
        EmployeeSignatureService $service
    ) {
        $employee = $this->employee($request);
        abort_unless((int) $signature->employee_id === (int) $employee->id, 404);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'is_default' => ['nullable', 'boolean'],
        ]);
        if (array_key_exists('name', $data)) {
            $signature->update(['name' => trim((string) $data['name'])]);
        }
        if (($data['is_default'] ?? false) === true) {
            $signature = $service->makeDefault($employee, $signature);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث التوقيع.',
            'signature' => $this->payload($signature->fresh()),
        ]);
    }

    public function destroy(
        Request $request,
        EmployeeSignature $signature,
        EmployeeSignatureService $service
    ) {
        $service->delete($this->employee($request), $signature);

        return response()->json(['status' => 'success', 'message' => 'تم حذف التوقيع من ملفك.']);
    }

    private function employee(Request $request): EmployeeDetail
    {
        $employee = $request->user()?->employee;
        abort_unless($employee, 403);

        return $employee;
    }

    /** @return array<string, mixed> */
    private function payload(EmployeeSignature $signature): array
    {
        return [
            'id' => $signature->id,
            'name' => $signature->name,
            'source' => $signature->source,
            'original_path' => $signature->original_path,
            'processed_path' => $signature->processed_path,
            'is_default' => (bool) $signature->is_default,
            'approved_at' => $signature->approved_at?->toIso8601String(),
            'created_at' => $signature->created_at?->toIso8601String(),
        ];
    }
}
