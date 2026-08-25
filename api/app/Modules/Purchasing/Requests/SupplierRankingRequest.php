<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Requests;

use App\Modules\Purchasing\Services\SupplierPerformanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRankingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.suppliers.performance.view') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('tier'))) {
            $this->merge(['tier' => strtoupper($this->input('tier'))]);
        }
    }

    public function rules(): array
    {
        return [
            'period_year'  => [
                'sometimes',
                'integer',
                'min:'.SupplierPerformanceService::MIN_PERIOD_YEAR,
                'max:'.SupplierPerformanceService::MAX_PERIOD_YEAR,
            ],
            'period_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'tier'         => ['sometimes', 'string', Rule::in(['A', 'B', 'C', 'D'])],
            'limit'        => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
