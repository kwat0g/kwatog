<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordLotResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.inspections.manage') ?? false;
    }

    /**
     * Inputs:
     *   checklist: [
     *     { id: <hash_id>, is_pass: bool, notes?: string },
     *     ...
     *   ]
     *   measurements: [
     *     { id: <hash_id>, measured_value?: number },
     *     ...
     *   ]
     *   sample_defect_count: int (0..sample_size) — may be null on a draft
     *     save (the sample has not been counted yet); required to complete
     *   complete: bool
     *
     * Hash IDs are decoded in prepareForValidation() so the service receives
     * integer-keyed maps.
     */
    protected function prepareForValidation(): void
    {
        // Decode checklist rows
        $checklist = $this->input('checklist');
        if (is_array($checklist)) {
            $decoded = [];
            foreach ($checklist as $row) {
                if (! is_array($row)) {
                    $decoded[] = ['_id' => null];
                    continue;
                }

                $rawId = array_key_exists('id', $row) ? $row['id'] : null;
                $id = is_string($rawId)
                    ? InspectionMeasurement::tryDecodeHash($rawId)
                    : (is_int($rawId) ? $rawId : null);
                $row['_id'] = $id;
                $decoded[] = $row;
            }
            $this->merge(['checklist' => $decoded]);
        }

        // Decode measurement rows
        $measurements = $this->input('measurements');
        if (is_array($measurements)) {
            $decoded = [];
            foreach ($measurements as $row) {
                if (! is_array($row)) {
                    $decoded[] = ['_id' => null];
                    continue;
                }

                $rawId = array_key_exists('id', $row) ? $row['id'] : null;
                $id = is_string($rawId)
                    ? InspectionMeasurement::tryDecodeHash($rawId)
                    : (is_int($rawId) ? $rawId : null);
                $row['_id'] = $id;
                $decoded[] = $row;
            }
            $this->merge(['measurements' => $decoded]);
        }
    }

    public function rules(): array
    {
        $inspection = $this->route('inspection');
        $sampleSize = $inspection instanceof Inspection ? (int) $inspection->sample_size : 0;

        return [
            'checklist' => ['present', 'array'],
            'checklist.*._id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'checklist.*.is_pass' => ['required', 'boolean'],
            'checklist.*.notes' => ['nullable', 'string', 'max:500'],

            'measurements' => ['present', 'array'],
            'measurements.*._id' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'measurements.*.measured_value' => [
                'nullable',
                'decimal:0,4',
                'between:-99999999.9999,99999999.9999',
            ],

            'sample_defect_count' => [
                Rule::requiredIf(fn () => $this->boolean('complete')),
                'nullable',
                'integer',
                'min:0',
                "max:{$sampleSize}",
            ],
            'complete' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{checklist: list<array>, measurements: list<array>, sample_defect_count: ?int, complete: bool}
     */
    public function decoded(): array
    {
        $validated = $this->validated();

        return [
            'checklist' => $validated['checklist'] ?? [],
            'measurements' => $validated['measurements'] ?? [],
            // Null keeps a draft uncounted; 0 would read back as "none found".
            'sample_defect_count' => isset($validated['sample_defect_count'])
                ? (int) $validated['sample_defect_count']
                : null,
            'complete' => (bool) ($validated['complete'] ?? false),
        ];
    }
}
