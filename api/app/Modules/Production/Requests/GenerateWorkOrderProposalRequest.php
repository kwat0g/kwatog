<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Modules\Production\Services\WorkOrderAssignmentService;
use Illuminate\Foundation\Http\FormRequest;

class GenerateWorkOrderProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor && app(WorkOrderAssignmentService::class)->canAssign($actor, $this->route('workOrder'))
            && ($actor->hasPermission('production.wo.confirm') || $actor->hasPermission('production.schedule.confirm'));
    }

    public function rules(): array
    {
        return [
            'minimum_workers' => ['sometimes', 'required', 'integer', 'min:1', 'max:4'],
            'maximum_workers' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4'],
            'preferred_production_date' => ['sometimes', 'nullable', 'date_format:Y-m-d',
                'after_or_equal:'.now()->toDateString(), 'before_or_equal:'.now()->addDays(90)->toDateString()],
            'rework_ncr_id' => ['prohibited'],
        ];
    }
}
