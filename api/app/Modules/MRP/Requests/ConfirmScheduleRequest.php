<?php

declare(strict_types=1);

namespace App\Modules\MRP\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Production\Models\ProductionSchedule;
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
        return ['schedule_ids.*' => ProductionSchedule::class];
    }

    public function rules(): array
    {
        return [
            'schedule_ids'   => ['required', 'array', 'min:1'],
            'schedule_ids.*' => ['integer', 'exists:production_schedules,id'],
        ];
    }
}
