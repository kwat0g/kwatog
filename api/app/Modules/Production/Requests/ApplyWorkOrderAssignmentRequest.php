<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Modules\Production\Models\ProductionSchedule;

class ApplyWorkOrderAssignmentRequest extends AssignWorkOrderCrewRequest
{
    protected function hashIdFields(): array
    {
        return array_merge(parent::hashIdFields(), ['schedule_id' => ProductionSchedule::class]);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), ['rework_ncr_id' => ['prohibited'],
            'schedule_id' => ['required', 'integer', 'exists:production_schedules,id'],
            'preferred_production_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addDays(90)->toDateString()],
            'schedule_updated_at' => ['required', 'date']]);
    }
}
