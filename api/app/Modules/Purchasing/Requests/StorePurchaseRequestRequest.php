<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.pr.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'department_id'              => ['nullable', 'integer', 'exists:departments,id'],
            'date'                       => ['nullable', 'date'],
            'reason'                     => ['nullable', 'string', 'max:1000'],
            'priority'                   => ['nullable', Rule::in(PurchaseRequestPriority::values())],
            'items'                      => ['required', 'array', 'min:1'],
            'items.*.item_id'            => ['nullable'],
            'items.*.description'        => ['required', 'string', 'max:200'],
            'items.*.quantity'           => ['required', 'decimal:0,2', 'min:0.01'],
            'items.*.unit'               => ['nullable', 'string', 'max:20'],
            'items.*.estimated_unit_price' => ['nullable', 'decimal:0,2', 'min:0'],
            'items.*.purpose'            => ['nullable', 'string', 'max:200'],
        ];
    }
}
