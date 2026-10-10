<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPortalDeliveryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('receiver_name'))) {
            $this->merge(['receiver_name' => trim($this->input('receiver_name'))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receiver_name'     => ['required', 'string', 'max:100'],
            'receiver_position' => ['nullable', 'string', 'max:100'],
            'delivery_remarks'  => ['nullable', 'string', 'max:500'],
            'accept_rejection' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.delivery_item_id' => ['required', 'string', 'distinct'],
            'lines.*.received_quantity' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
        ];
    }
}
