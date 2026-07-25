<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetCode;
use Illuminate\Http\Request;

class AdminPasswordResetCodeController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();
        if (! $admin || $admin->type !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized. Admins only.',
            ], 200);
        }

        $status = strtolower((string) $request->query('status', 'all'));
        $search = trim((string) $request->query('search', ''));
        $limit = min(max((int) $request->query('limit', 100), 1), 200);

        $query = PasswordResetCode::query()
            ->leftJoin('users', 'users.id', '=', 'password_reset_codes.user_id')
            ->select([
                'password_reset_codes.*',
                'users.name as user_name',
                'users.type as user_type',
            ])
            ->orderByDesc('password_reset_codes.created_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('password_reset_codes.email', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%");
            });
        }

        if ($status === 'used') {
            $query->whereNotNull('password_reset_codes.used_at');
        } elseif ($status === 'expired') {
            $query->whereNull('password_reset_codes.used_at')
                ->whereNotNull('password_reset_codes.expires_at')
                ->where('password_reset_codes.expires_at', '<', now());
        } elseif ($status === 'active') {
            $query->whereNull('password_reset_codes.used_at')
                ->where(function ($q) {
                    $q->whereNull('password_reset_codes.expires_at')
                        ->orWhere('password_reset_codes.expires_at', '>=', now());
                });
        }

        $rows = $query->limit($limit)->get();

        return response()->json([
            'status' => 'success',
            'codes' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'user_name' => (string) ($row->user_name ?: ''),
                'user_type' => (string) ($row->user_type ?: ''),
                'email' => (string) $row->email,
                'token' => (string) $row->token,
                'delivery_method' => (string) ($row->delivery_method ?: 'email'),
                'status' => $this->statusFor($row),
                'created_at' => optional($row->created_at)->toIso8601String(),
                'expires_at' => optional($row->expires_at)->toIso8601String(),
                'used_at' => optional($row->used_at)->toIso8601String(),
            ])->values(),
        ], 200);
    }

    private function statusFor(PasswordResetCode $code): string
    {
        if ($code->used_at !== null) {
            return 'used';
        }

        if ($code->expires_at !== null && $code->expires_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }
}
