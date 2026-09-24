<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('budgeting.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:1900', 'max:2200', 'unique:fiscal_years,year'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }
}
