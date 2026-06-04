<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class ShipmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shipped_date'      => ['nullable', 'date'],
            'carrier'           => ['nullable', 'string', 'max:100'],
            'tracking_number'   => ['nullable', 'string', 'max:100'],
            'estimated_arrival' => ['nullable', 'date'],
            'notes'             => ['nullable', 'string', 'max:500'],
        ];
    }
}
