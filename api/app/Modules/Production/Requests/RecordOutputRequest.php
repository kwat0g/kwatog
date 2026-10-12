<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecordOutputRequest extends FormRequest
{
    use ResolvesHashIds {
        prepareForValidation as private resolveHashIdFields;
    }

    public function authorize(): bool
    {
        if ($this->user()?->hasPermission('production.wo.record')) {
            return true;
        }
        if (! $this->user()) {
            return false;
        }
        app(WorkOrderAssignmentService::class)->internal($this->user());

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->resolveHashIdFields();
        $workOrder = $this->route('workOrder');
        if (! $workOrder instanceof WorkOrder || ! $workOrder->usesFinishFirstQc() || $this->route('ncr') !== null
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
        return ['defects.*.defect_type_id' => DefectType::class, 'crew_id' => WorkOrderCrew::class];
    }

    public function rules(): array
    {
        $workOrder = $this->route('workOrder');
        $finishFirst = $workOrder instanceof WorkOrder && $workOrder->usesFinishFirstQc() && $this->route('ncr') === null;

        return [
            // The shared service requires a crew for new writes; legacy exact retries may omit it.
            'crew_id' => ['nullable', 'integer', 'exists:work_order_crews,id'],
            'recording_reason' => ['nullable', 'string', 'max:500'],
            'material_handover_acknowledged' => ['sometimes', 'boolean'],
            'material_handover_reason' => ['nullable', 'required_if:material_handover_acknowledged,true', 'string', 'max:500'],
            'qc_workflow' => ['prohibited'],
            'produced_count' => $finishFirst ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'good_count' => $finishFirst ? ['prohibited'] : ['required', 'integer', 'min:0'],
            'reject_count' => $finishFirst ? ['prohibited'] : ['required', 'integer', 'min:0'],
            'shift' => ['nullable', 'string', 'max:20'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'defects' => $finishFirst ? ['prohibited'] : ['nullable', 'array'],
            'defects.*.defect_type_id' => ['required_with:defects', 'integer', 'exists:defect_types,id'],
            'defects.*.count' => ['required_with:defects', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $workOrder = $this->route('workOrder');
            if ($workOrder instanceof WorkOrder && $workOrder->usesFinishFirstQc() && $this->route('ncr') === null) {
                return;
            }

            $good = (int) $this->input('good_count');
            $reject = (int) $this->input('reject_count');

            if ($good + $reject <= 0) {
                $v->errors()->add('good_count', 'At least one of Good count or Reject count must be greater than zero.');
            }

            if ($reject > 0) {
                $defects = $this->input('defects');
                if (! is_array($defects) || empty($defects)) {
                    $v->errors()->add('defects', 'You must specify the defect types when the Reject count is greater than zero.');
                }
            } elseif (is_array($this->input('defects')) && ! empty($this->input('defects'))) {
                $v->errors()->add('defects', 'Defect rows require a positive Reject count.');
            }
        });
    }
}
