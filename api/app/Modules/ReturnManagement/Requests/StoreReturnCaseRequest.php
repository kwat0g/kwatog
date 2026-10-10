<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReturnCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->is('api/v1/b2b/customer/*')
            ? $this->user('customer_portal') !== null
            : ($this->user()?->hasPermission('return_management.manage')
                || $this->user()?->hasPermission('inventory.grn.create'));
    }

    public function rules(): array
    {
        $reportOnly = ! $this->is('api/v1/b2b/customer/*')
            && ! $this->user()?->hasPermission('return_management.manage');
        $quantity = ['required', 'decimal:0,3', 'min:0', 'max:999999999.999'];
        return [
            'source_kind' => ['required', Rule::in($reportOnly ? ['grn'] : ['delivery', 'grn', 'purchase_order'])],
            'source_id' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'preferred_resolution' => ['required', Rule::in($reportOnly ? ['advice'] : ['redelivery', 'credit', 'advice'])],
            'request_key' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => ['required', 'array:source_line_id,expected_quantity,received_quantity,defective_quantity,lot_number,serial_number,reason'],
            'lines.*.source_line_id' => ['required', 'string', 'max:100', 'distinct'],
            'lines.*.expected_quantity' => ['nullable', 'decimal:0,3', 'min:0', 'max:999999999.999'],
            'lines.*.received_quantity' => $quantity,
            'lines.*.defective_quantity' => $reportOnly ? [...$quantity, 'lte:0'] : $quantity,
            'lines.*.lot_number' => ['nullable', 'string', 'max:120'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:120'],
            'lines.*.reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
