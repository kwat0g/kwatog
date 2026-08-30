<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Quality\Models\InspectionMeasurement;
use Illuminate\Foundation\Http\FormRequest;

class RecordMeasurementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('quality.inspections.manage') ?? false;
    }

    /**
     * Inputs:
     *   measurements: [
     *     { id: <hash_id>, measured_value?: number|null, is_pass?: bool|null, notes?: string|null },
     *     ...
     *   ]
     *
     * The hash_ids come straight from the InspectionResource payload that
     * the frontend rendered. We decode them in prepareForValidation() so the
     * service receives a clean integer-keyed map.
     */
    protected function prepareForValidation(): void
    {
        $rows = $this->input('measurements');
        if (! is_array($rows)) return;

        $decoded = [];
        foreach ($rows as $row) {
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

    public function rules(): array
    {
        return [
            'measurements'                    => ['required', 'array', 'min:1'],
            'measurements.*._id'              => ['required', 'integer', 'min:1', 'distinct:strict'],
            // M056 — `numeric` alone accepted more than the decimal(12,4)
            // column can hold. `10.00005` was silently rounded to `10.0001`
            // and then evaluated, so the stored evidence was not the reading
            // the inspector entered; `1e20` and `99999999999999` overflowed
            // the column and 500'd. Bound both the scale and the magnitude so
            // an out-of-range reading is a 422 the operator can see and
            // correct, never a rounded value presented as measured fact.
            'measurements.*.measured_value'   => [
                'nullable',
                'decimal:0,4',
                'between:-99999999.9999,99999999.9999',
            ],
            'measurements.*.is_pass'          => ['nullable', 'boolean'],
            'measurements.*.notes'            => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, array{measured_value?: float|string|null, is_pass?: bool|null, notes?: string|null}>
     */
    public function decodedRows(): array
    {
        $out = [];
        foreach ($this->validated()['measurements'] ?? [] as $row) {
            $id = (int) $row['_id'];
            $patch = [];
            if (array_key_exists('measured_value', $row)) $patch['measured_value'] = $row['measured_value'];
            if (array_key_exists('is_pass', $row))        $patch['is_pass']        = $row['is_pass'];
            if (array_key_exists('notes', $row))          $patch['notes']          = $row['notes'];
            $out[$id] = $patch;
        }
        return $out;
    }
}
