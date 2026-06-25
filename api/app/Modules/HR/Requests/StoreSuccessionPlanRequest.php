<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\SuccessionPriority;
use App\Modules\HR\Enums\SuccessionReadiness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSuccessionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.succession.manage');
    }

    public function rules(): array
    {
        return [
            'position_id'       => ['required', 'integer', 'exists:positions,id'],
            'incumbent_id'      => ['nullable', 'integer', 'exists:employees,id'],
            'successor_id'      => ['required', 'integer', 'exists:employees,id'],
            'readiness'         => ['required', Rule::in(SuccessionReadiness::values())],
            'priority'          => ['nullable', Rule::in(SuccessionPriority::values())],
            'development_notes' => ['nullable', 'string', 'max:5000'],
            'target_date'       => ['nullable', 'date', 'after:today'],
        ];
    }
}
