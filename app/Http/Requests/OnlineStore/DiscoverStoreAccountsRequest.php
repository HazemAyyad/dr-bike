<?php

namespace App\Http\Requests\OnlineStore;

use Illuminate\Foundation\Http\FormRequest;

class DiscoverStoreAccountsRequest extends FormRequest
{
    use AuthorizesOnlineStoreResource;

    public const PERMISSION = 'Online Store Settings Manage';

    public function authorize(): bool
    {
        $this->authorizeOnlineStorePermission(self::PERMISSION);

        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
