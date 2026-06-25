<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\SuccessionPriority;
use App\Modules\HR\Enums\SuccessionReadiness;
use App\Modules\HR\Enums\SuccessionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSuccessionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.succession.manage');
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
