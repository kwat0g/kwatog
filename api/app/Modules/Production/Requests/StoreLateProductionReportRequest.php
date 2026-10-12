<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLateProductionReportRequest extends FormRequest
{
    use ResolvesHashIds;

    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor?->is_active) {
            return false;
        }

        app(WorkOrderAssignmentService::class)->internal($actor);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $workOrder = $this->route('workOrder');
        if (! $workOrder instanceof WorkOrder || ! $workOrder->usesFinishFirstQc()
            || $this->exists('produced_count') || ! $this->exists('good_count')) {
            return;
        }

        if (($this->exists('reject_count') && filter_var($this->input('reject_count'), FILTER_VALIDATE_INT) !== 0)
            || ($this->exists('defects') && $this->input('defects') !== [])) {
            return;
        }

        // Older finish-first clients send good_count; this endpoint stores produced_count.
        $this->merge(['produced_count' => $this->input('good_count')]);
        $this->getInputSource()->remove('good_count');
        $this->getInputSource()->remove('reject_count');
        $this->getInputSource()->remove('defects');
    }

    protected function hashIdFields(): array
    {
        return ['crew_id' => WorkOrderCrew::class, 'defects.*.defect_type_id' => DefectType::class];
    }

    public function rules(): array
    {
        $workOrder = $this->route('workOrder');
        $finishFirst = $workOrder instanceof WorkOrder && $workOrder->usesFinishFirstQc();

        return [
            'qc_workflow' => ['prohibited'],
            'crew_id' => ['required', 'integer', 'exists:work_order_crews,id'],
            'source_reference' => ['required', 'string', 'max:120'],
            'production_started_at' => ['required', 'date'],
            'production_finished_at' => ['required', 'date', 'after:production_started_at'],
            'produced_count' => $finishFirst ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'good_count' => $finishFirst ? ['prohibited'] : ['required', 'integer', 'min:0'],
            'reject_count' => $finishFirst ? ['prohibited'] : ['required', 'integer', 'min:0'],
            'defects' => $finishFirst ? ['prohibited'] : ['sometimes', 'array'],
            'defects.*.defect_type_id' => ['required_with:defects', 'integer', Rule::exists('defect_types', 'id')->where('is_active', true)],
            'defects.*.count' => ['required_with:defects', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $workOrder = $this->route('workOrder');
            if ($workOrder instanceof WorkOrder && $workOrder->usesFinishFirstQc()) {
                return;
            }
            $reject = (int) $this->input('reject_count', 0);
            $rows = $this->input('defects', []);
            $sum = is_array($rows) ? array_sum(array_map(static fn ($row) => (int) ($row['count'] ?? 0), $rows)) : 0;
            $types = is_array($rows) ? array_column($rows, 'defect_type_id') : [];

            if ((int) $this->input('good_count', 0) + $reject < 1) {
                $validator->errors()->add('good_count', 'At least one physical piece must be reported.');
            }
            if (($reject > 0 && (! is_array($rows) || $rows === [] || $sum !== $reject))
                || ($reject === 0 && is_array($rows) && $rows !== [])) {
                $validator->errors()->add('defects', 'Defect counts must account for every rejected piece and use no rows when Reject count is zero.');
            }
            if (count($types) !== count(array_unique($types))) {
                $validator->errors()->add('defects', 'Use each defect type once and report its total affected pieces.');
            }
        });
    }
}
