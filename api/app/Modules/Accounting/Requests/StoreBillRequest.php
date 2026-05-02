<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.bills.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'bill_number'                  => ['required', 'string', 'max:50'],
            'vendor_id'                    => ['required', 'string'],
            'purchase_order_id'            => ['nullable'],
            'allow_override'               => ['nullable', 'boolean'],
            'date'                         => ['required', 'date'],
            'due_date'                     => ['nullable', 'date', 'after_or_equal:date'],
            'is_vatable'                   => ['nullable', 'boolean'],
            'remarks'                      => ['nullable', 'string', 'max:1000'],
            'items'                        => ['required', 'array', 'min:1'],
            'items.*.expense_account_id'   => ['required', 'string'],
            'items.*.description'          => ['required', 'string', 'max:200'],
            'items.*.quantity'             => ['required', 'numeric', 'min:0.01'],
            'items.*.unit'                 => ['nullable', 'string', 'max:20'],
            'items.*.unit_price'           => ['required', 'numeric', 'min:0'],
        ];
    }
}
