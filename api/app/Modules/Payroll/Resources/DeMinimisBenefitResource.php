<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Payroll\Models\DeMinimisBenefit
 */
class DeMinimisBenefitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'employee'            => $this->whenLoaded('employee', fn () => [
                'id'        => $this->employee->hash_id,
                'full_name' => $this->employee->full_name,
            ]),
            'benefit_type'        => $this->benefit_type?->value,
            'benefit_type_label'  => $this->benefit_type?->label(),
            'monthly_limit'       => $this->benefit_type?->monthlyLimit(),
            'amount'              => $this->amount,
            'period_year'         => $this->period_year,
            'period_month'        => $this->period_month,
            'is_taxable_portion'  => $this->is_taxable_portion,
            'notes'               => $this->notes,
            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }
}
