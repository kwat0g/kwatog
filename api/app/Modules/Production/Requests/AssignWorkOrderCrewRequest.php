<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Foundation\Http\FormRequest;

class AssignWorkOrderCrewRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.wo.assign') ?? false;
    }

    protected function hashIdFields(): array
    {
        return ['employee_ids.*' => Employee::class, 'presence_confirmed_employee_ids.*' => Employee::class, 'recorder_employee_id' => Employee::class,
            'expected_crew_id' => WorkOrderCrew::class, 'rework_ncr_id' => NonConformanceReport::class];
    }

    public function rules(): array
    {
        return ['employee_ids' => ['required', 'array', 'min:1', 'max:4'],
            'employee_ids.*' => ['required', 'integer', 'distinct', 'exists:employees,id'],
            'recorder_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'minimum_workers' => ['required', 'integer', 'min:1', 'max:4'],
            'maximum_workers' => ['nullable', 'integer', 'min:1', 'max:4', 'gte:minimum_workers'],
            'expected_crew_id' => ['present', 'nullable', 'integer', 'exists:work_order_crews,id'],
            'rework_ncr_id' => ['nullable', 'integer', 'exists:non_conformance_reports,id'],
            'presence_confirmed_employee_ids' => ['sometimes', 'array', 'max:4'],
            'presence_confirmed_employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            '_reviewed_slot' => ['prohibited'], '_allow_holiday' => ['prohibited'],
            'reason' => ['required', 'string', 'max:500'],
            'shift_id' => ['prohibited'], 'starts_at' => ['prohibited'], 'ends_at' => ['prohibited']];
    }
}
