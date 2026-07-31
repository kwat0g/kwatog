<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\HR\Enums\SuccessionPriority;
use App\Modules\HR\Enums\SuccessionReadiness;
use App\Modules\HR\Enums\SuccessionStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSuccessionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.succession.manage');
    }

    /**
     * See StoreSuccessionPlanRequest — hashed ids decoded before the
     * `integer|exists` rules run; undecodable becomes 0 so `exists` 422s.
     */
    protected function prepareForValidation(): void
    {
        foreach ([
            'position_id'  => Position::class,
            'incumbent_id' => Employee::class,
            'successor_id' => Employee::class,
        ] as $field => $model) {
            $raw = $this->input($field);
            if ($raw === null || $raw === '') {
                continue;
            }
            $this->merge([$field => HashIdFilter::decode($raw, $model) ?? 0]);
        }
    }

    public function rules(): array
    {
        return [
            'position_id'       => ['sometimes', 'integer', 'exists:positions,id'],
            'incumbent_id'      => ['nullable', 'integer', 'exists:employees,id'],
            'successor_id'      => ['sometimes', 'integer', 'exists:employees,id'],
            'readiness'         => ['sometimes', Rule::in(SuccessionReadiness::values())],
            'priority'          => ['sometimes', Rule::in(SuccessionPriority::values())],
            'status'            => ['sometimes', Rule::in(SuccessionStatus::values())],
            'development_notes' => ['nullable', 'string', 'max:5000'],
            'target_date'       => ['nullable', 'date'],
        ];
    }
}
