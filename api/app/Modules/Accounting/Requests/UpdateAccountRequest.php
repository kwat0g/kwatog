<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\NormalBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.coa.manage') ?? false;
    }

    public function rules(): array
    {
        $accountId = $this->route('account')?->id ?? null;
        return [
            'code'           => ['sometimes', 'string', 'regex:/^[0-9]{3,6}$/', Rule::unique('accounts', 'code')->ignore($accountId)],
            'name'           => ['sometimes', 'string', 'max:150'],
            'type'           => ['sometimes', Rule::in(AccountType::values())],
            'normal_balance' => ['sometimes', Rule::in(NormalBalance::values())],
            'parent_id'      => ['sometimes', 'nullable', 'string'],
            'description'    => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active'      => ['sometimes', 'boolean'],
        ];
    }
}
