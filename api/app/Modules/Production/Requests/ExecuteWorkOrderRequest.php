<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Models\ProductionSchedule;
use Illuminate\Foundation\Http\FormRequest;

class ExecuteWorkOrderRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->is_active && $this->user()->hasPermission('production.work_orders.lifecycle');
    }

    protected function hashIdFields(): array
    {
        return ['expected_schedule_id' => ProductionSchedule::class, 'expected_crew_id' => WorkOrderCrew::class, 'presence_confirmed_employee_ids.*' => Employee::class];
    }

    public function rules(): array
    {
        return ['late_start_authorized' => ['sometimes', 'boolean'],
            'expected_schedule_id' => ['required_if:late_start_authorized,true', 'nullable', 'integer', 'exists:production_schedules,id'],
            'expected_schedule_updated_at' => ['required_with:expected_schedule_id', 'nullable', 'date'],
            'expected_crew_id' => ['required', 'integer', 'exists:work_order_crews,id'],
            'presence_confirmed_employee_ids' => ['sometimes', 'array', 'max:4'],
            'presence_confirmed_employee_ids.*' => ['integer', 'distinct', 'exists:employees,id']];
    }
}
