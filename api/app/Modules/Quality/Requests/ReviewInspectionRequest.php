<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\InspectionOutcome;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.inspections.review') ?? false;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([
                InspectionStatus::Passed->value,
                InspectionStatus::Failed->value,
            ])],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $inspection = $this->route('inspection');
            if (! $inspection instanceof Inspection) {
                return;
            }

            $decision = (string) $this->input('decision');
            $proposed = $inspection->proposed_result instanceof InspectionOutcome
                ? $inspection->proposed_result->value
                : (string) $inspection->proposed_result;
            $override = $decision === InspectionStatus::Passed->value
                && $proposed === InspectionOutcome::Failed->value;
            if (($decision === InspectionStatus::Failed->value || $override)
                && trim((string) $this->input('remarks')) === '') {
                $validator->errors()->add('remarks', 'Remarks are required for a failed disposition or override.');
            }
        });
    }
}
