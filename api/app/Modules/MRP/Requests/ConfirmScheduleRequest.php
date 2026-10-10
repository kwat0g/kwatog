<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrderCrew;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmScheduleRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.schedule.confirm') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['schedules.*.schedule_id' => ProductionSchedule::class, 'schedules.*.expected_crew_id' => WorkOrderCrew::class];
    }

    public function rules(): array
    {
        return [
            'schedules' => ['required', 'array', 'min:1', 'max:100'],
            'schedules.*.schedule_id' => ['required', 'integer', 'distinct', 'exists:production_schedules,id'],
            'schedules.*.schedule_updated_at' => ['required', 'date'],
            'schedules.*.expected_crew_id' => ['required', 'integer', 'exists:work_order_crews,id'],
        ];
    }
}
