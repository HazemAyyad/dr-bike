<?php

namespace App\Http\Requests\OnlineStore;

use App\Models\OnlineStore\OnlineStoreAccountLink;
use App\Support\OnlineStore\OnlineStoreValues;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManageAccountLinkRequest extends FormRequest
{
    use AuthorizesOnlineStoreResource;

    public const PERMISSION = 'Online Store Settings Manage';

    public function authorize(): bool
    {
        $resource = $this->route('link');
        if ($resource instanceof OnlineStoreAccountLink) {
            $this->authorizeOnlineStoreResource(self::PERMISSION, true);

            return true;
        }
        $this->authorizeOnlineStorePermission(self::PERMISSION);

        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', 'exists:users,id'],
            'role' => [$this->isMethod('post') ? 'required' : 'sometimes', OnlineStoreValues::accountRoleRule()],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id', 'required_if:role,customer', 'prohibited_if:role,seller'],
            'seller_id' => ['nullable', 'integer', 'exists:sellers,id', 'required_if:role,seller', 'prohibited_if:role,customer'],
            'account_source' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(OnlineStoreValues::ACCOUNT_SOURCES)],
            'status' => [$this->isMethod('post') ? 'required' : 'sometimes', OnlineStoreValues::accountStatusRule()],
            'search' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
