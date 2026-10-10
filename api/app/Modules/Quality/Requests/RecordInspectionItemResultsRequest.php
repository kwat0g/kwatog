<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Quality\Models\InspectionMeasurement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordInspectionItemResultsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.inspections.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');
        if (! is_array($items)) {
            return;
        }
        $decoded = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $decoded[] = ['id' => null];

                continue;
            }
            $id = $item['id'] ?? null;
            $item['id'] = is_string($id) ? InspectionMeasurement::tryDecodeHash($id) : (is_int($id) ? $id : null);
            if (is_string($item['problem_description'] ?? null)) {
                $item['problem_description'] = trim($item['problem_description']);
            }
            $decoded[] = $item;
        }
        $this->merge(['items' => $decoded]);
    }

    public function rules(): array
    {
        $rules = [
            'expected_result_version' => [$this->route('inspection')?->usesChecklistCapture() ? 'required' : 'sometimes', 'integer', 'min:0'],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'items.*.result' => ['required', Rule::in(['passed', 'failed'])],
            'items.*.problem_description' => ['nullable', 'string', 'max:500', 'required_if:items.*.result,failed'],
            'submit' => ['sometimes', 'boolean'],
        ];
        if ($this->route('inspection')?->usesChecklistCapture()) {
            $rules['items.*.measured_value'] = ['sometimes', 'nullable', 'regex:/^-?\d{1,8}(?:\.\d{1,4})?$/'];
            foreach (['nominal_value', 'tolerance_min', 'tolerance_max', 'is_pass'] as $field) {
                $rules["items.*.$field"] = ['prohibited'];
            }
        }

        return $rules;
    }
}
