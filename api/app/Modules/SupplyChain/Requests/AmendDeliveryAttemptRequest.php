<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

class AmendDeliveryAttemptRequest extends StoreDeliveryAttemptOutcomeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'expected_version' => ['required', 'integer', 'min:1'],
            'correction_reason' => ['required', 'string', 'max:2000'],
        ]);
    }
}
