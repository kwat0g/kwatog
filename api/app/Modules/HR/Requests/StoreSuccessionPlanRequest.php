<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Common\Support\HashIdFilter;
use App\Modules\HR\Enums\SuccessionPriority;
use App\Modules\HR\Enums\SuccessionReadiness;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSuccessionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.succession.manage');
    }

    /**
     * The SPA sends hashed ids; the columns are bigint and the rules below are
     * `integer|exists`. Decode first so the rules see the real key — otherwise
     * every create from the browser fails validation, and any value that slipped
     * through would hit Postgres as a string (22P02). An undecodable hash
     * becomes 0 so `exists` rejects it with a 422 instead of failing open.
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
