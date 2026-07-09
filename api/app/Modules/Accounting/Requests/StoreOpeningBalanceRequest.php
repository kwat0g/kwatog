<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.opening_balance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'date'                 => ['required', 'date'],
            'lines'                => ['required', 'array', 'min:2'],
            'lines.*.account_id'   => ['required'],
            'lines.*.debit'        => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'       => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
