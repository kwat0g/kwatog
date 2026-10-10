<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrderCrew;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmWorkOrderRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('production.wo.confirm') ?? false;
    }

    protected function hashIdFields(): array
    {
        return [
            'machine_id' => Machine::class,
            'mold_id' => Mold::class, 'schedule_id' => ProductionSchedule::class, 'expected_crew_id' => WorkOrderCrew::class,
        ];
    }

    public function rules(): array
    {
        return [
            'schedule_id' => ['required', 'integer', 'exists:production_schedules,id'],
            'schedule_updated_at' => ['required', 'date'],
            'expected_crew_id' => ['required', 'integer', 'exists:work_order_crews,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'mold_id' => ['nullable', 'integer', 'exists:molds,id'],
        ];
    }
}
