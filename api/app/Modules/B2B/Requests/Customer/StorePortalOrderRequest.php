<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StorePortalOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date'                  => ['sometimes', 'nullable', 'date'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.product_id'    => ['required', 'string'],
            'items.*.quantity'      => ['required', 'numeric', 'min:0.01'],
            'items.*.delivery_date' => ['required', 'date'],
        ];
    }
}
