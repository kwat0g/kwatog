<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Requests;

use App\Modules\Maintenance\Enums\MaintenanceScheduleInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaintenanceScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.schedules.manage');
    }

    public function rules(): array
    {
        return [
            'description'       => ['sometimes', 'required', 'string', 'max:200'],
            'interval_type'     => ['sometimes', 'required', Rule::in(MaintenanceScheduleInterval::values())],
            'interval_value'    => ['sometimes', 'required', 'integer', 'min:1'],
            'last_performed_at' => ['nullable', 'date'],
            'is_active'         => ['nullable', 'boolean'],
        ];
    }
}
