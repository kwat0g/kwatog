<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ConfirmPortalReceiptGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('receiver_name'))) {
            $this->merge(['receiver_name' => trim($this->input('receiver_name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'receiver_name' => ['required', 'string', 'max:100'],
            'receiver_position' => ['nullable', 'string', 'max:100'],
            'delivery_remarks' => ['nullable', 'string', 'max:500'],
            'shipments' => ['required', 'array', 'min:1', 'max:100'],
            'shipments.*' => ['required', 'array:delivery_id,lines'],
            'shipments.*.delivery_id' => ['required', 'string', 'distinct'],
            'shipments.*.lines' => ['required', 'array', 'min:1'],
            'shipments.*.lines.*' => ['required', 'array:delivery_item_id,received_quantity'],
            'shipments.*.lines.*.delivery_item_id' => ['required', 'string', 'distinct'],
            'shipments.*.lines.*.received_quantity' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['version', 'receiver_name', 'receiver_position', 'delivery_remarks', 'shipments'])) {
                $validator->errors()->add('shipments', 'Only receipt quantities and receiver details can be submitted.');
            }
        }];
    }
}
