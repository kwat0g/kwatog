<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

class ExtendWorkOrderRunRequest extends ExecuteWorkOrderRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'expected_schedule_id' => ['required', 'integer', 'exists:production_schedules,id'],
            'expected_schedule_updated_at' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/', 'after:now'],
            'reason' => ['required', 'string', 'max:500'],
            'late_production_authorized' => ['sometimes', 'boolean'],
            'late_production_reason' => ['required_if:late_production_authorized,true', 'nullable', 'string', 'max:500'],
        ]);
    }
}
