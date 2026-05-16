<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Support\BankShortcut;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BanksController extends Controller
{
    public function index()
    {
        $banks = Bank::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Bank $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'shortcut' => $b->shortcut,
                'sort_order' => $b->sort_order,
            ]);

        return response()->json([
            'status' => 'success',
            'banks' => $banks,
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255', 'unique:banks,name'],
                'shortcut' => ['nullable', 'string', 'max:50'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ]);

            $bank = Bank::create([
                'name' => $data['name'],
                'shortcut' => $data['shortcut'] ?? BankShortcut::infer($data['name']),
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_created_successfully'),
                'bank' => $bank,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        }
    }

    public function update(Request $request, int $id)
    {
        try {
            $bank = Bank::findOrFail($id);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255', 'unique:banks,name,'.$id],
                'shortcut' => ['nullable', 'string', 'max:50'],
                'sort_order' => ['nullable', 'integer', 'min:0'],
            ]);

            if (! array_key_exists('shortcut', $data) || $data['shortcut'] === null) {
                $data['shortcut'] = BankShortcut::infer($data['name']);
            }
            $bank->update($data);

            return response()->json([
                'status' => 'success',
                'message' => __('messages.employee_task_updated_successfully'),
                'bank' => $bank,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
                'errors' => $e->errors(),
            ], 200);
        }
    }

    public function destroy(int $id)
    {
        $bank = Bank::findOrFail($id);
        $bank->delete();

        return response()->json([
            'status' => 'success',
            'message' => __('messages.employee_task_canceled'),
        ], 200);
    }

    /** إنشاء بنك بالاسم إن لم يكن موجوداً (لنموذج الشيك). */
    public function findOrCreate(Request $request)
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json([
                'status' => 'error',
                'message' => __('messages.validation_failed'),
            ], 200);
        }

        $bank = Bank::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if (! $bank) {
            $bank = Bank::create([
                'name' => $name,
                'shortcut' => BankShortcut::infer($name),
                'sort_order' => (int) Bank::max('sort_order') + 1,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'bank' => $bank,
        ], 200);
    }
}
