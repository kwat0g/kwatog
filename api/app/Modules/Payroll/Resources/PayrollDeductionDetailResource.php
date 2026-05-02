<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Payroll\Models\PayrollDeductionDetail
 */
class PayrollDeductionDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'deduction_type'       => $this->deduction_type?->value,
            'deduction_type_label' => $this->deduction_type?->label(),
            'description'          => $this->description,
            'amount'               => $this->amount,
            'reference_id'         => $this->reference_id, // raw int — internal only
        ];
    }
}
