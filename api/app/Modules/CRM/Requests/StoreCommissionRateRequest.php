<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommissionRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('crm.commissions.manage');
    }

    public function rules(): array
    {
        return [
            'employee_id'     => ['required', 'integer', 'exists:employees,id'],
            'product_id'      => ['nullable', 'integer', 'exists:products,id'],
            'rate'            => ['required', 'decimal:0,4', 'min:0', 'max:1'],
            'effective_from'  => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
