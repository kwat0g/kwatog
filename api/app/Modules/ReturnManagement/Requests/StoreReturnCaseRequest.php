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
            : ($this->user()?->hasPermission('return_management.manage') ?? false);
    }

    public function rules(): array
    {
        $quantity = ['required', 'decimal:0,3', 'min:0', 'max:999999999.999'];
        return [
            'source_kind' => ['required', Rule::in(['delivery', 'grn', 'purchase_order'])],
            'source_id' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'preferred_resolution' => ['required', Rule::in(['redelivery', 'credit', 'advice'])],
            'request_key' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.source_line_id' => ['required', 'string', 'max:100', 'distinct'],
            'lines.*.expected_quantity' => ['nullable', 'decimal:0,3', 'min:0', 'max:999999999.999'],
            'lines.*.received_quantity' => $quantity,
            'lines.*.defective_quantity' => $quantity,
            'lines.*.lot_number' => ['nullable', 'string', 'max:120'],
            'lines.*.serial_number' => ['nullable', 'string', 'max:120'],
            'lines.*.reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
