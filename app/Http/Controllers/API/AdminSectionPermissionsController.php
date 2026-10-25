<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminSectionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminSectionPermissionsController extends Controller
{
    public function show(Request $request, int $id)
    {
        abort_unless($request->user()?->isFullAdmin(), 403);
        $admin = User::where('type', 'admin')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'full_access' => $admin->isFullAdmin(),
            'sections' => $admin->admin_sections ?? [],
            'can_edit' => (int) $request->user()->id !== (int) $admin->id,
            'available_sections' => collect(AdminSectionAccess::SECTIONS)
                ->map(fn ($name, $key) => ['key' => $key, 'name' => $name])->values(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($request->user()?->isFullAdmin(), 403);
        abort_if((int) $request->user()->id === $id, 422, 'لا يمكنك تعديل صلاحيات حسابك الحالي.');
        $data = $request->validate([
            'full_access' => ['required', 'boolean'],
            'sections' => ['present', 'array'],
            'sections.*' => ['string', 'distinct', Rule::in(array_keys(AdminSectionAccess::SECTIONS))],
        ]);

        DB::transaction(function () use ($id, $data) {
            $admin = User::where('type', 'admin')->lockForUpdate()->findOrFail($id);
            $admin->forceFill(['admin_sections' => $data['full_access'] ? null : array_values($data['sections'])])->save();
            Logs::createLog('تعديل صلاحيات أدمن', 'تم تعديل الأقسام المتاحة للأدمن '.$admin->name, 'admins');
        });

        return response()->json(['status' => 'success', 'message' => 'تم حفظ صلاحيات الأدمن.']);
    }
}
