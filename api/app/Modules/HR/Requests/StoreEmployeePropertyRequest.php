<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.employees.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'item_name'            => ['required', 'string', 'max:200'],
            'description'          => ['nullable', 'string', 'max:1000'],
            'quantity'             => ['required', 'integer', 'min:1'],
            'replacement_unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'date_issued'          => ['required', 'date'],
            'date_returned'        => ['nullable', 'date', 'after_or_equal:date_issued'],
            'status'               => ['sometimes', 'string', 'in:issued,returned,lost'],
        ];
    }
}