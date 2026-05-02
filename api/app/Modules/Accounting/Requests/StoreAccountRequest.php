<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\NormalBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.coa.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code'           => ['required', 'string', 'regex:/^[0-9]{3,6}$/', 'unique:accounts,code'],
            'name'           => ['required', 'string', 'max:150'],
            'type'           => ['required', Rule::in(AccountType::values())],
            'normal_balance' => ['nullable', Rule::in(NormalBalance::values())],
            'parent_id'      => ['nullable', 'string'],
            'description'    => ['nullable', 'string', 'max:500'],
        ];
    }
}
