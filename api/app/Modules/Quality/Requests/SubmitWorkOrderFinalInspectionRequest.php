<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitWorkOrderFinalInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->hasPermission($this->route()->getActionMethod() === 'review'
            ? 'quality.inspections.review' : 'quality.inspections.manage');
    }

    public function rules(): array
    {
        $review = $this->route()->getActionMethod() === 'review';
        return [
            'manifest' => ['required', 'array', 'min:1'],
            'manifest.*' => ['array:output_id,inspection_id,physical_quantity,result_version,specification_revision_id'],
            'manifest.*.output_id' => ['required', 'string'],
            'manifest.*.inspection_id' => ['required', 'string', 'distinct'],
            'manifest.*.physical_quantity' => ['required', 'integer', 'min:1'],
            'manifest.*.result_version' => ['required', 'integer', 'min:0'],
            'manifest.*.specification_revision_id' => ['required', 'string'],
            'batches' => $review ? ['prohibited'] : ['required', 'array', 'min:1'],
            'batches.*' => ['array:inspection_id,coverage_confirmed,inspected_quantity,checks_confirmed'],
            'batches.*.inspection_id' => ['required', 'string', 'distinct'],
            'batches.*.checks_confirmed' => ['sometimes', 'boolean'],
            'batches.*.coverage_confirmed' => ['required', 'accepted'],
            'batches.*.inspected_quantity' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'result' => ['prohibited'], 'decision' => ['prohibited'],
        ];
    }
}
