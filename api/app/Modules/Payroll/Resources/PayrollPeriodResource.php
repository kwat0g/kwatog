<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Payroll\Models\PayrollPeriod
 */
class PayrollPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'period_start'        => optional($this->period_start)->toDateString(),
            'period_end'          => optional($this->period_end)->toDateString(),
            'payroll_date'        => optional($this->payroll_date)->toDateString(),
            'is_first_half'       => (bool) $this->is_first_half,
            'is_thirteenth_month' => (bool) $this->is_thirteenth_month,
            'status'              => $this->status?->value,
            'status_label'        => $this->status?->label(),
            'is_locked'           => $this->isLocked(),
            'label'               => $this->label(),
            'employee_count'      => (int) ($this->payrolls_count ?? 0),

            'creator'             => $this->whenLoaded('creator', fn () => [
                'id'   => $this->creator?->hash_id,
                'name' => $this->creator?->name,
            ]),

            // Optional summary block — attached as a dynamic attribute by
            // PayrollPeriodService::show()/list() so detail pages get totals
            // without a second round trip and the index can show net pay too.
            'summary'             => $this->resource->summary ?? null,

            'created_at'          => optional($this->created_at)->toIso8601String(),
            'updated_at'          => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
