<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.employees.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'item_name'            => ['sometimes', 'string', 'max:200'],
            'description'          => ['sometimes', 'nullable', 'string', 'max:1000'],
            'quantity'             => ['sometimes', 'integer', 'min:1'],
            'replacement_unit_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'date_issued'          => ['sometimes', 'date'],
            'date_returned'        => ['sometimes', 'nullable', 'date'],
            'status'               => ['sometimes', 'string', 'in:issued,returned,lost'],
        ];
    }
}