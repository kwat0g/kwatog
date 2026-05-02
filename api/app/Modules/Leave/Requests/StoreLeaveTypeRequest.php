<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('leave.types.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'                         => ['required', 'string', 'max:100'],
            'code'                         => ['required', 'string', 'max:10', 'unique:leave_types,code'],
            'default_balance'              => ['required', 'numeric', 'min:0', 'max:999.9'],
            'is_paid'                      => ['boolean'],
            'requires_document'            => ['boolean'],
            'is_convertible_on_separation' => ['boolean'],
            'is_convertible_year_end'      => ['boolean'],
            'conversion_rate'              => ['nullable', 'numeric', 'min:0', 'max:9.99'],
            'is_active'                    => ['boolean'],
        ];
    }
}
