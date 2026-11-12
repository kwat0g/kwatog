<?php

declare(strict_types=1);

namespace App\Modules\Quality\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Exceptions\NcrStateConflictException;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Illuminate\Contracts\Queue\ShouldQueue;

class CloseReworkNcrAfterQcApproval implements ShouldQueue
{
    public function __construct(private readonly NcrService $ncrs) {}

    public function handle(InspectionPassed $event): void
    {
        $inspection = Inspection::query()->with('workOrderOutput')->find($event->inspection->id);
        $output = $inspection?->workOrderOutput;
        if (! $inspection || $inspection->stage !== InspectionStage::Outgoing
            || $inspection->status !== InspectionStatus::Passed
            || ! $inspection->canReleaseOutgoing()
            || ! $output?->rework_ncr_id
            || (int) $inspection->accepted_quantity !== (int) $output->good_count) {
            $this->record('skipped', 'inspection_is_not_a_released_rework_output');

            return;
        }

        $ncr = NonConformanceReport::query()
            ->with(['inspection', 'reworkWorkOrder'])
            ->find($output->rework_ncr_id);
        $sourceInspection = $ncr?->inspection;
        if (! $ncr || $ncr->status->isTerminal() || $ncr->disposition !== NcrDisposition::Rework
            || ! $sourceInspection
            || $sourceInspection->stage !== InspectionStage::Outgoing
            || $sourceInspection->work_order_output_id === null
            || ($sourceInspection->counted_capture['workflow'] ?? null) !== 'finish_first_v1'
            || ! $ncr->sorting_approved_at || ! $ncr->mrb_decided_at
            || $ncr->confirmed_rework_quantity === null) {
            $this->record('skipped', 'ncr_is_not_ready_for_rework_auto_close');

            return;
        }

        $repair = $ncr->reworkWorkOrder;
        if (! $repair) {
            $this->record('skipped', 'repair_work_order_is_missing');

            return;
        }

        $inlineRepair = (int) $repair->id === (int) $sourceInspection->entity_id;
        if (($inlineRepair && ! $ncr->rework_finished_at)
            || (! $inlineRepair && (! in_array($repair->status->value, ['completed', 'closed'], true)
                || (int) $repair->quantity_produced !== (int) $repair->quantity_target
                || ((int) $repair->quantity_good > 0 && ! $repair->outputs()->where('good_count', '>', 0)->exists())))) {
            $this->record('skipped', 'repair_work_order_is_not_finished');

            return;
        }

        $repairOutputs = $repair->outputs()
            ->when($inlineRepair, fn ($query) => $query->where('rework_ncr_id', $ncr->id))
            ->where('good_count', '>', 0)
            ->orderBy('id')
            ->get();
        if ($repairOutputs->isEmpty()) {
            $this->record('skipped', 'repair_has_no_good_output_for_qc_release');

            return;
        }

        foreach ($repairOutputs as $repairOutput) {
            $qc = $repairOutput->inspections()
                ->where('stage', InspectionStage::Outgoing->value)
                ->where('status', '!=', InspectionStatus::Cancelled->value)
                ->orderByDesc('id')
                ->first();
            if (! $qc || ((! $qc->canReleaseOutgoing()
                    || (int) $qc->accepted_quantity !== (int) $repairOutput->good_count)
                && ! $qc->hasResolvedOutgoingDisposition())) {
                $this->record('skipped', 'repair_output_still_needs_approved_qc');

                return;
            }
        }

        $checker = $inspection->reviewed_by ? User::query()->find($inspection->reviewed_by) : null;
        if (! $checker) {
            $this->record('manual_required', 'qc_checker_is_unavailable_for_ncr_audit');

            return;
        }

        try {
            $this->ncrs->close($ncr, $checker);
        } catch (NcrStateConflictException $exception) {
            if ($ncr->fresh()?->status->isTerminal()) {
                $this->record('skipped', 'ncr_was_closed_concurrently');

                return;
            }

            throw $exception;
        }

        $this->record('completed', 'rework_ncr_auto_closed_after_qc_approval');
    }

    private function record(string $outcome, string $code): void
    {
        app(ChainListenerRunService::class)->recordOutcome($outcome, $code);
    }
}
