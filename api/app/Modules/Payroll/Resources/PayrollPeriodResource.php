<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use App\Modules\Payroll\Enums\BankFileGenerationStatus;
use App\Modules\Payroll\Enums\PayrollGlHandoffStatus;
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
            'lifecycle_steps'     => $this->lifecycleSteps(),
            'is_locked'           => $this->isLocked(),
            'label'               => $this->label(),

            // Scope — which slice of the workforce this run pays. All null /
            // empty means company-wide, which is what every pre-scoping period
            // is. department ids are surfaced as hash ids only.
            'is_company_wide'        => $this->isCompanyWide(),
            'scope_label'            => $this->scopeLabel(),
            'scope_employment_types' => $this->scope_employment_types ?? [],
            'scope_pay_types'        => $this->scope_pay_types ?? [],
            'scope_departments'      => $this->isCompanyWide() || empty($this->scope_department_ids)
                ? []
                : \App\Modules\HR\Models\Department::query()
                    ->whereIn('id', (array) $this->scope_department_ids)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($d) => ['id' => $d->hash_id, 'name' => $d->name])
                    ->all(),
            'disbursement_status' => $this->disbursement_status ?? 'pending',
            'disbursed_at'        => optional($this->disbursed_at)->toIso8601String(),
            'disburser'           => $this->whenLoaded('disburser', fn () => [
                'id'   => $this->disburser?->hash_id,
                'name' => $this->disburser?->name,
            ]),
            'is_auto_created'     => (bool) $this->is_auto_created,
            'auto_created_at'     => optional($this->auto_created_at)->toIso8601String(),
            'employee_count'      => (int) ($this->payrolls_count ?? 0),

            // The finalized payroll may exist before its bank artifact does;
            // expose that handoff explicitly instead of making operators infer
            // it from an empty bank_files collection.
            'bank_file_status'       => $this->bank_file_status?->value ?? BankFileGenerationStatus::NotStarted->value,
            'bank_file_status_label' => $this->bank_file_status?->label() ?? BankFileGenerationStatus::NotStarted->label(),
            'bank_file_note'         => $this->bank_file_note,
            'bank_file_at'           => optional($this->bank_file_at)->toIso8601String(),

            // Compute-run telemetry. processing_started_at lets the UI say how
            // long a run has been going; is_compute_stale flags a claim whose
            // worker is presumed dead, so the page can offer a retry instead of
            // spinning forever. compute_progress is the last broadcast snapshot
            // (PayrollProgressTracker) and backs the progress bar on first paint
            // and whenever the websocket is unavailable.
            //
            // Both are short-circuited off the status so list endpoints do no
            // per-row settings or cache lookups for the common non-running case.
            'processing_started_at' => optional($this->processing_started_at)->toIso8601String(),
            'is_compute_stale'      => $this->status === \App\Modules\Payroll\Enums\PayrollPeriodStatus::Processing
                && app(\App\Modules\Payroll\Services\PayrollPeriodService::class)->claimIsStale($this->resource),
            'compute_progress'      => $this->status === \App\Modules\Payroll\Enums\PayrollPeriodStatus::Processing
                ? app(\App\Modules\Payroll\Services\PayrollProgressTracker::class)->get($this->resource)
                : null,

            // REC-01 — void audit trail (populated once a finalized period is
            // voided). voider name is eager-loaded when available.
            'voided_at'           => optional($this->voided_at)->toIso8601String(),
            'void_reason'         => $this->void_reason,
            'voider'              => $this->whenLoaded('voider', fn () => [
                'id'   => $this->voider?->hash_id,
                'name' => $this->voider?->name,
            ]),

            'creator'             => $this->whenLoaded('creator', fn () => [
                'id'   => $this->creator?->hash_id,
                'name' => $this->creator?->name,
            ]),

            // REC-04 — maker-checker attribution. computer = HR maker who ran
            // Compute; approver/finalizer = the checker(s) who signed off.
            // Timestamps are ISO8601; user refs never leak the integer id.
            'approved_at'         => optional($this->approved_at)->toIso8601String(),
            'finalized_at'        => optional($this->finalized_at)->toIso8601String(),
            'computer'            => $this->whenLoaded('computer', fn () => [
                'id'   => $this->computer?->hash_id,
                'name' => $this->computer?->name,
            ]),
            'approver'            => $this->whenLoaded('approver', fn () => [
                'id'   => $this->approver?->hash_id,
                'name' => $this->approver?->name,
            ]),
            'finalizer'           => $this->whenLoaded('finalizer', fn () => [
                'id'   => $this->finalizer?->hash_id,
                'name' => $this->finalizer?->name,
            ]),

            // Optional summary block — attached as a dynamic attribute by
            // PayrollPeriodService::show()/list() so detail pages get totals
            // without a second round trip and the index can show net pay too.
            'summary'             => $this->resource->summary ?? null,

            // GL link (set by service.show() when the period has been posted).
            // We expose only the human-readable entry_number, never the integer id.
            'gl_entry_number'     => $this->resource->gl_entry_number ?? null,
            'gl_handoff_status'       => $this->gl_handoff_status?->value ?? PayrollGlHandoffStatus::NotStarted->value,
            'gl_handoff_status_label' => $this->gl_handoff_status?->label() ?? PayrollGlHandoffStatus::NotStarted->label(),
            'gl_handoff_note'         => $this->gl_handoff_note,
            'gl_handoff_at'           => optional($this->gl_handoff_at)->toIso8601String(),

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

            // ADV1 — Disbursement proofs.
            'disbursement_proofs' => $this->whenLoaded('disbursementProofs', fn () =>
                $this->disbursementProofs->map(fn ($p) => [
                    'id'                   => $p->hash_id,
                    'proof_type'           => $p->proof_type,
                    'proof_type_label'     => \App\Modules\Payroll\Enums\DisbursementProofType::tryFrom((string) $p->proof_type)?->label(),
                    'file_name'            => $p->file_name,
                    'bank_name'            => $p->bank_name,
                    'transaction_reference' => $p->transaction_reference,
                    'disbursed_amount'     => $p->disbursed_amount,
                    'disbursement_date'    => optional($p->disbursement_date)->toDateString(),
                    'notes'                => $p->notes,
                    'uploader'             => $p->relationLoaded('uploader') && $p->uploader
                        ? ['id' => $p->uploader->hash_id, 'name' => $p->uploader->name]
                        : null,
                    'created_at'           => optional($p->created_at)->toIso8601String(),
                    'deleted_at'           => optional($p->deleted_at)?->toIso8601String(),
                ])->all(),
            ),

            'created_at'          => optional($this->created_at)->toIso8601String(),
            'updated_at'          => optional($this->updated_at)->toIso8601String(),
        ];
    }

    /** @return array<int, array{key:string,label:string,state:string}> */
    private function lifecycleSteps(): array
    {
        $status = $this->status?->value ?? (string) $this->getRawOriginal('status');
        $completed = static fn (array $statuses): string => in_array($status, $statuses, true) ? 'done' : 'pending';

        return [
            ['key' => 'draft', 'label' => 'Draft', 'state' => 'done'],
            ['key' => 'processing', 'label' => 'Computed', 'state' => $status === 'draft' ? 'pending' : ($status === 'processing' ? 'active' : 'done')],
            ['key' => 'approved', 'label' => 'Approved', 'state' => $completed(['approved', 'finalized', 'disbursed'])],
            ['key' => 'finalized', 'label' => 'Finalized', 'state' => $completed(['finalized', 'disbursed'])],
            ['key' => 'disbursed', 'label' => 'Disbursed', 'state' => $completed(['disbursed'])],
        ];
    }
}
