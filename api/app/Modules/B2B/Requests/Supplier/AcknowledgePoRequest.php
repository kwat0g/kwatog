<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Supplier;

use Illuminate\Foundation\Http\FormRequest;

class AcknowledgePoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_delivery_date' => ['nullable', 'date'],
            'notes'                  => ['nullable', 'string', 'max:500'],
        ];
    }
}
