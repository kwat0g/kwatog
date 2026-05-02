<?php

declare(strict_types=1);

namespace App\Modules\Loans\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->hash_id,
            'amount'       => (string) $this->amount,
            'payment_date' => optional($this->payment_date)->toDateString(),
            'payment_type' => $this->payment_type,
            'remarks'      => $this->remarks,
        ];
    }
}
