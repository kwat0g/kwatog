<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Payroll\Models\GovernmentContributionTable
 */
class GovernmentTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'agency'         => $this->agency?->value,
            'agency_label'   => $this->agency?->label(),
            'bracket_min'    => $this->bracket_min,
            'bracket_max'    => $this->bracket_max,
            'ee_amount'      => $this->ee_amount,
            'er_amount'      => $this->er_amount,
            'effective_date' => optional($this->effective_date)->toDateString(),
            'is_active'      => $this->is_active,
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'updated_at'     => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
