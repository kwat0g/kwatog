<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\HR\Models\Employee;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use Illuminate\Foundation\Http\FormRequest;

class ReviewWorkOrderSlotRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        $actor = $this->user();
        $wo = $this->route('workOrder');

        return $actor && app(WorkOrderAssignmentService::class)->canAssign($actor, $wo)
            && ($actor->hasPermission('production.schedule.confirm') || $actor->hasPermission('production.wo.confirm'))
            && ($wo->status->value === 'confirmed' || $actor->hasPermission('production.work_orders.lifecycle'));
    }

    protected function hashIdFields(): array
    {
        return ['machine_id' => Machine::class, 'mold_id' => Mold::class, 'expected_schedule_id' => ProductionSchedule::class, 'employee_ids.*' => Employee::class,
            'recorder_employee_id' => Employee::class, 'expected_crew_id' => WorkOrderCrew::class];
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ['minimum_workers' => ['sometimes', 'integer', 'min:1', 'max:4'], 'manual_repair' => ['sometimes', 'boolean'],
                'preferred_production_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString(), 'before_or_equal:'.now()->addDays(90)->toDateString()],
                'preferred_date' => ['sometimes', 'date_format:Y-m-d',
                    'after_or_equal:'.now()->toDateString(), 'before_or_equal:'.now()->addDays(90)->toDateString()]];
        }
        $timestamp = ['required', 'date', 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/'];

        return ['scheduled_start' => $timestamp, 'scheduled_end' => [...$timestamp, 'after:scheduled_start'],
            'reason' => ['required', 'string', 'max:500'],
            'preferred_production_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString(), 'before_or_equal:'.now()->addDays(90)->toDateString()],
            'holiday_authorized' => ['sometimes', 'boolean'],
            'late_production_authorized' => ['sometimes', 'boolean'],
            'late_production_reason' => ['required_if:late_production_authorized,true', 'nullable', 'string', 'max:500'],
            'employee_ids' => ['sometimes', 'array', 'min:1', 'max:4'],
            'employee_ids.*' => ['required', 'integer', 'distinct', 'exists:employees,id'],
            'recorder_employee_id' => ['required_with:employee_ids', 'integer', 'exists:employees,id'],
            'expected_crew_id' => ['present_with:employee_ids', 'nullable', 'integer', 'exists:work_order_crews,id'],
            'expected_schedule_id' => ['present', 'nullable', 'integer', 'exists:production_schedules,id'],
            'expected_schedule_updated_at' => ['present', 'nullable', 'date'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'mold_id' => ['nullable', 'integer', 'exists:molds,id']];
    }
}
