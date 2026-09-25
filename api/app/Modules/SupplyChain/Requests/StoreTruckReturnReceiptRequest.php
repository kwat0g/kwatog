<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTruckReturnReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('return_management.receive') ?? false;
    }

    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'quarantine_location_id' => ['nullable', 'string', 'max:100'],
            'variance_reason' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.delivery_item_id' => ['required', 'string', 'max:100', 'distinct'],
            'lines.*.received_quantity' => ['required', 'decimal:0,3', 'min:0', 'max:999999999.999'],
        ];
    }
}
