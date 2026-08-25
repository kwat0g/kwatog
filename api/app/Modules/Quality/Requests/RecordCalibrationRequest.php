<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordCalibrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('quality.calibration.manage') === true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
