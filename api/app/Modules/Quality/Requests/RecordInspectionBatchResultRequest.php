<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Quality\Models\InspectionMeasurement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordInspectionBatchResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.inspections.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('inspection')?->counted_capture) {
            $measurements = $this->input('measurements');
            if (is_array($measurements)) {
                $this->merge(['measurements' => array_map(static function ($row) {
                    if (! is_array($row)) {
                        return ['id' => null];
                    }
                    $row['id'] = is_string($row['id'] ?? null) ? InspectionMeasurement::tryDecodeHash($row['id']) : null;

                    return $row;
                }, $measurements)]);
            }

            return;
        }
        if (! $this->route('inspection')?->usesChecklistCapture()) {
            return;
        }
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }
        $this->merge(['items' => array_map(static function ($row) {
            if (! is_array($row)) {
                return ['id' => null];
            }
            $row['id'] = is_string($row['id'] ?? null)
                ? InspectionMeasurement::tryDecodeHash($row['id']) : null;

            return $row;
        }, $items)]);
    }

    public function rules(): array
    {
        if ($this->route('inspection')?->counted_capture) {
            $lot = $this->route('inspection')->sampling_basis === 'lot';

            return [
                'expected_result_version' => ['required', 'integer', 'min:0'],
                'coverage_confirmed' => ['required', 'accepted'],
                'inspected_quantity' => ['required', 'integer', 'min:1'],
                'sample_defect_count' => $lot ? ['prohibited'] : ['required', 'integer', 'min:0'],
                'physical_defects_confirmed' => $lot ? ['required', 'boolean'] : ['prohibited'],
                'problem_description' => ['nullable', 'string', 'max:2000'],
                'requirement_problem' => ['sometimes', 'boolean'],
                'defects_removed_confirmed' => ['sometimes', 'boolean'],
                'measurements' => ['present', 'array', 'max:1000'],
                'measurements.*.id' => ['required', 'integer', 'distinct:strict'],
                'measurements.*.measured_value' => ['required', 'regex:/^-?\d{1,8}(?:\.\d{1,4})?$/'],
                'measurements.*.is_pass' => ['prohibited'],
                'measurements.*.tolerance_min' => ['prohibited'],
                'measurements.*.tolerance_max' => ['prohibited'],
                'result' => ['prohibited'], 'items' => ['prohibited'], 'critical_defect' => ['prohibited'],
            ];
        }
        if ($this->route('inspection')?->usesChecklistCapture()) {
            return [
                'expected_result_version' => ['required', 'integer', 'min:0'],
                'items' => ['required', 'array', 'min:1', 'max:100'],
                'items.*.id' => ['required', 'integer', 'distinct:strict'],
                'items.*.result' => ['required', Rule::in(['passed', 'failed'])],
                'items.*.problem_description' => ['nullable', 'string', 'max:500'],
                'items.*.measured_value' => ['sometimes', 'nullable', 'regex:/^-?\d{1,8}(?:\.\d{1,4})?$/'], 'items.*.nominal_value' => ['prohibited'],
                'items.*.tolerance_min' => ['prohibited'], 'items.*.tolerance_max' => ['prohibited'], 'items.*.is_pass' => ['prohibited'],
                'coverage_confirmed' => ['required', 'accepted'],
                'inspected_quantity' => ['sometimes', 'integer', 'min:1'],
                'physical_defects_confirmed' => ['sometimes', 'boolean'],
                'sample_defect_count' => ['sometimes', 'integer', 'min:0'],
                'defects_removed_confirmed' => ['sometimes', 'boolean'],
                'problem_description' => ['nullable', 'string', 'max:2000'],
                'result' => ['prohibited'], 'critical_defect' => ['prohibited'],
                'measured_value' => ['prohibited'], 'nominal_value' => ['prohibited'], 'tolerance_min' => ['prohibited'], 'tolerance_max' => ['prohibited'],
            ];
        }

        return [
            'result' => ['required', Rule::in(['passed', 'failed'])],
            'coverage_confirmed' => ['required', 'accepted'],
            'inspected_quantity' => ['required', 'integer', 'min:1'],
            'sample_defect_count' => ['required', 'integer', 'min:0'],
            'problem_description' => ['nullable', 'string', 'max:2000', 'required_if:result,failed'],
            'critical_defect' => ['required', 'boolean'],
            'defects_removed_confirmed' => ['required', 'boolean'],
        ];
    }
}
