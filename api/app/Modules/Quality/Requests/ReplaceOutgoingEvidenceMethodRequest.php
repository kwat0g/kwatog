<?php

declare(strict_types=1);

namespace App\Modules\Quality\Requests;

class ReplaceOutgoingEvidenceMethodRequest extends SubmitWorkOrderFinalInspectionRequest
{
    public function rules(): array
    {
        return array_filter(parent::rules(), fn ($key) => str_starts_with($key, 'manifest'), ARRAY_FILTER_USE_KEY)
            + ['reason' => ['required', 'string', 'min:1', 'max:2000']];
    }
}
