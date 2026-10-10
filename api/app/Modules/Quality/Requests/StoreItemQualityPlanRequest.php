<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Enums\QualityPlanSamplingMethod;
use App\Modules\Quality\Support\ChecklistDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreItemQualityPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.specs.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'capture_method' => ['sometimes', Rule::in(['measurement', 'checklist'])],
            'parameters.*.acceptance_criterion' => ['nullable', 'string', 'max:500'],
            'vendor_id' => ['nullable', 'string'],
            'sampling_method' => ['required', Rule::enum(QualityPlanSamplingMethod::class)],
            'fixed_sample_size' => ['nullable', 'required_if:sampling_method,fixed', 'integer', 'min:1', 'max:1000'],
            'aql_level' => ['nullable', 'string', 'max:20'],
            'parameters' => ['required', 'array', 'min:1', 'max:100'],
            'parameters.*.parameter_name' => ['required', 'string', 'max:150'],
            'parameters.*.parameter_type' => [$this->input('capture_method') === 'checklist' ? 'nullable' : 'required', Rule::enum(InspectionParameterType::class)],
            'parameters.*.unit_of_measure' => ['nullable', 'string', 'max:20'],
            'parameters.*.nominal_value' => ['nullable', 'numeric'],
            'parameters.*.tolerance_min' => ['nullable', 'numeric'],
            'parameters.*.tolerance_max' => ['nullable', 'numeric'],
            'parameters.*.is_critical' => ['sometimes', 'boolean'],
            'parameters.*.notes' => ['nullable', 'string', 'max:500'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                if ($this->input('capture_method') === 'checklist') {
                    ChecklistDefinition::validate($this->input('parameters', []), 'parameters');
                } elseif (config('inspection.checklist_capture_enabled')) {
                    ChecklistDefinition::requireInstructions($this->input('parameters', []), 'parameters');
                }
            } catch (ValidationException $error) {
                foreach ($error->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }
}
