<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use App\Modules\Quality\Enums\CalibrationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCalibrationRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('quality.calibration.manage') === true;
    }

    public function rules(): array
    {
        $id = $this->route('calibrationRecord')?->id;

        return [
            'equipment_code'        => ['required', 'string', 'max:50', Rule::unique('calibration_records', 'equipment_code')->ignore($id)],
            'name'                  => ['required', 'string', 'max:150'],
            'location'              => ['nullable', 'string', 'max:100'],
            'last_calibration_date' => ['nullable', 'date'],
            'next_calibration_date' => ['nullable', 'date'],
            // Omitted means "keep the current value" on PATCH or the database
            // default on INSERT. An explicit null must fail before it reaches
            // the non-null database column.
            'frequency_days'        => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'status'                => ['nullable', Rule::in(CalibrationStatus::values())],
            'responsible'           => ['nullable', 'string', 'max:100'],
            'remarks'               => ['nullable', 'string', 'max:2000'],
        ];
    }
}
