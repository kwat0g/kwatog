<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveWorkOrderFinalInspectionEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->hasPermission('quality.inspections.manage');
    }

    public function rules(): array
    {
        return [
            'manifest' => ['required', 'array', 'min:1'],
            'manifest.*' => ['array:output_id,inspection_id,physical_quantity,result_version,specification_revision_id'],
            'manifest.*.output_id' => ['required', 'string'],
            'manifest.*.inspection_id' => ['required', 'string', 'distinct'],
            'manifest.*.physical_quantity' => ['required', 'integer', 'min:1'],
            'manifest.*.result_version' => ['required', 'integer', 'min:0'],
            'manifest.*.specification_revision_id' => ['required', 'string'],
            'inspection_id' => ['required', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'defect_page' => ['sometimes', 'integer', 'min:1'],
            'expected_result_version' => ['required', 'integer', 'min:0'],
            'defective_quantity' => ['required', 'integer', 'min:0'],
            'reset_defects' => ['sometimes', 'boolean'],
            'remove_piece_numbers' => ['sometimes', 'array', 'max:50'],
            'remove_piece_numbers.*' => ['integer', 'min:1', 'distinct'],
            'pieces' => ['present', 'array', 'max:50'],
            'pieces.*' => ['array:piece_number,problem_description,requirement_id'],
            'pieces.*.piece_number' => ['required', 'integer', 'min:1', 'distinct'],
            'pieces.*.requirement_id' => ['sometimes', 'nullable', 'string'],
            'pieces.*.problem_description' => ['present', 'nullable', 'string', 'min:1', 'max:500'],
            'readings' => ['present', 'array', 'max:5000'],
            'readings.*' => ['array:piece_number,requirement_id,measured_value'],
            'readings.*.piece_number' => ['required', 'integer', 'min:1'],
            'readings.*.requirement_id' => ['required', 'string'],
            'readings.*.measured_value' => ['present', 'nullable', 'regex:/^-?\d{1,8}(?:\.\d{1,4})?$/'],
            'result' => ['prohibited'], 'is_pass' => ['prohibited'], 'counted_capture' => ['prohibited'],
        ];
    }
}
