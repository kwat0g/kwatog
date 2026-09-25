<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ReportDeliveryDiscrepancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('customer_portal') !== null;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.delivery_item_id' => ['required', 'string'],
            'lines.*.received_quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D'],
            'rationale' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
