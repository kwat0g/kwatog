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

            // GL link (set by service.show() when the period has been posted).
            // We expose only the human-readable entry_number, never the integer id.
            'gl_entry_number'     => $this->resource->gl_entry_number ?? null,

            // Bank file disbursement audit trail. Only the count, total, and
            // generator metadata — file paths stay server-side (private disk).
            'bank_files'          => $this->whenLoaded('bankFileRecords', fn () =>
                $this->bankFileRecords->map(fn ($r) => [
                    'id'           => $r->hash_id,
                    'record_count' => (int) $r->record_count,
                    'total_amount' => $r->total_amount,
                    'generated_at' => optional($r->generated_at)->toIso8601String(),
                    'generator'    => $r->relationLoaded('generator') && $r->generator
                        ? ['id' => $r->generator->hash_id, 'name' => $r->generator->name]
                        : null,
                ])->all(),
            ),

            // Adjustment summary for this period (counts only — full list lives
            // on /payroll/adjustments).
            'adjustment_counts'   => $this->whenLoaded('adjustments', fn () => [
                'pending'  => $this->adjustments->where('status', \App\Modules\Payroll\Enums\PayrollAdjustmentStatus::Pending)->count(),
                'approved' => $this->adjustments->where('status', \App\Modules\Payroll\Enums\PayrollAdjustmentStatus::Approved)->count(),
                'applied'  => $this->adjustments->where('status', \App\Modules\Payroll\Enums\PayrollAdjustmentStatus::Applied)->count(),
                'rejected' => $this->adjustments->where('status', \App\Modules\Payroll\Enums\PayrollAdjustmentStatus::Rejected)->count(),
            ]),

            'created_at'          => optional($this->created_at)->toIso8601String(),
            'updated_at'          => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
