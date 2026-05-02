<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunThirteenthMonthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('payroll.thirteenth_month.run') ?? false;
    }

    public function rules(): array
    {
        return [
            'year'         => ['required', 'integer', 'min:2020', 'max:2100'],
            'payroll_date' => ['nullable', 'date'],
        ];
    }
}
