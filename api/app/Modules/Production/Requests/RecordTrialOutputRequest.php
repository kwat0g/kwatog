<?php

declare(strict_types=1);

namespace App\Modules\Production\Requests;

use App\Modules\Production\Models\WorkOrderCrew;
use Illuminate\Validation\Validator;

class RecordTrialOutputRequest extends RecordOutputRequest
{
    protected function hashIdFields(): array
    {
        return ['crew_id' => WorkOrderCrew::class];
    }

    public function rules(): array
    {
        return [
            'crew_id' => ['required', 'integer', 'exists:work_order_crews,id'],
            'trial_quantity' => ['required', 'integer', 'min:1'],
            'recording_reason' => ['nullable', 'string', 'max:500'],
            'material_handover_acknowledged' => ['sometimes', 'boolean'],
            'material_handover_reason' => ['nullable', 'required_if:material_handover_acknowledged,true', 'string', 'max:500'],
            'good_count' => ['prohibited'],
            'reject_count' => ['prohibited'],
            'rework_ncr_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void {}
}
