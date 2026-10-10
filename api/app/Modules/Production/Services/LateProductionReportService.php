<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Enums\LateProductionReportStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\LateProductionReport;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Services\WorkOrderFinalInspectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LateProductionReportService
{
    public function __construct(private readonly WorkOrderAssignmentService $assignments) {}

    public function ownReports(User $actor, WorkOrder $wo): Builder
    {
        $this->assignments->assertCanReadHistorical($actor, $wo);

        return LateProductionReport::query()->where('work_order_id', $wo->id)->where('recorded_by', $actor->id);
    }

    public function propose(WorkOrder $workOrder, User $actor, array $data, string $idempotencyKey): LateProductionReport
    {
        abort_unless($actor->is_active, 403);
        $start = Carbon::parse($data['production_started_at'])->timezone(config('app.timezone'));
        $finish = Carbon::parse($data['production_finished_at'])->timezone(config('app.timezone'));
        $crewId = (int) $data['crew_id'];
        $crew = $this->assignments->assertCanSubmitHistorical($actor, $workOrder, $crewId);
        $reference = trim((string) $data['source_reference']);
        $reason = trim((string) $data['reason']);
        $finishFirst = $workOrder->usesFinishFirstQc();
        if ($finishFirst) {
            if (filter_var($data['produced_count'] ?? null, FILTER_VALIDATE_INT) === false || (int) $data['produced_count'] <= 0
                || array_key_exists('good_count', $data) || array_key_exists('reject_count', $data) || array_key_exists('defects', $data)) {
                throw new BusinessRuleException('Finish-first historical output requires one positive whole produced_count and no QC classification fields.');
            }
            $good = (int) $data['produced_count'];
            $reject = 0;
            $defects = [];
        } else {
            if (array_key_exists('produced_count', $data)) {
                throw new BusinessRuleException('produced_count is supported only for finish-first work orders.');
            }
            $good = (int) $data['good_count'];
            $reject = (int) $data['reject_count'];
            $defects = collect($data['defects'] ?? [])->map(static fn (array $defect): array => [
                'defect_type_id' => (int) $defect['defect_type_id'],
                'count' => (int) $defect['count'],
            ])->sortBy('defect_type_id')->values()->all();
        }

        if ($good < 0 || $reject < 0 || $good + $reject < 1
            || ($finishFirst
                ? (int) $workOrder->quantity_produced + $good > (int) $workOrder->quantity_target
                : $good > (int) $workOrder->quantity_target)) {
            throw new BusinessRuleException('Reported counts must contain physical pieces and stay within the work-order target.');
        }
        if (($reject > 0 && array_sum(array_column($defects, 'count')) !== $reject)
            || ($reject === 0 && $defects !== [])) {
            throw new BusinessRuleException('Defect rows must account for every reported rejected piece.');
        }
        if ($reference === '' || $reason === '' || $idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new BusinessRuleException('A source reference, late-entry reason and submission key are required.');
        }

        $fingerprint = hash('sha256', json_encode([
            'recorded_by' => (int) $actor->id,
            'crew_id' => $crewId,
            'source_reference' => $reference,
            'production_started_at' => $start->copy()->utc()->toISOString(),
            'production_finished_at' => $finish->copy()->utc()->toISOString(),
            'good_count' => $good,
            'reject_count' => $reject,
            'defects' => $defects,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $workOrder, $actor, $crew, $start, $finish, $reference, $reason, $good, $reject,
            $defects, $idempotencyKey, $fingerprint, $finishFirst,
        ): LateProductionReport {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            $existing = LateProductionReport::query()->where('work_order_id', $wo->id)
                ->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->recorded_by !== (int) $actor->id || ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
                    throw new BusinessRuleException('This submission key was already used for a different historical report.');
                }

                return $existing;
            }

            if ($wo->usesFinishFirstQc() !== $finishFirst) {
                throw new BusinessRuleException('The work order QC workflow changed. Reload before submitting a historical report.');
            }

            $this->assertValidInterval($wo, $crew, $start, $finish);
            if ($wo->status === WorkOrderStatus::Cancelled) {
                throw new BusinessRuleException('Cancelled work orders cannot receive production output reports.');
            }
            if (LateProductionReport::query()->where('work_order_id', $wo->id)
                ->whereRaw('lower(source_reference) = ?', [mb_strtolower($reference)])->exists()) {
                throw new BusinessRuleException('This source batch already has a late production report. Reconcile the existing report.');
            }
            if (LateProductionReport::query()->where('work_order_id', $wo->id)
                ->where('production_started_at', '<', $finish)->where('production_finished_at', '>', $start)->exists()) {
                throw new BusinessRuleException('This interval overlaps another historical report for the work order. Reconcile the batches before submitting.');
            }

            $evidence = $this->evidenceSummary($wo, $crew, $start, $finish);
            $snapshot = $this->crewSnapshot($crew);

            return LateProductionReport::query()->forceCreate([
                'work_order_id' => $wo->id,
                'work_order_crew_id' => $crew->id,
                'recorded_by' => $actor->id,
                'source_reference' => $reference,
                'production_started_at' => $start->copy()->utc(),
                'production_finished_at' => $finish->copy()->utc(),
                'good_count' => $good,
                'reject_count' => $reject,
                'defects' => $defects,
                'reason' => $reason,
                'crew_snapshot' => $snapshot,
                'evidence_summary' => $evidence,
                'status' => $wo->status === WorkOrderStatus::Closed
                    ? LateProductionReportStatus::Correction->value : LateProductionReportStatus::Pending->value,
                'idempotency_key' => $idempotencyKey,
                'idempotency_fingerprint' => $fingerprint,
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
        });
    }

    private function assertValidInterval(WorkOrder $wo, WorkOrderCrew $crew, Carbon $start, Carbon $finish): void
    {
        if ($finish->lte($start) || $finish->gt(now()) || $start->gt(now()) || $start->diffInSeconds($finish) > 86400) {
            throw new BusinessRuleException('Production interval must be a past, forward interval no longer than 24 hours.');
        }
        if (! $wo->actual_start || $start->lt($wo->actual_start)) {
            throw new BusinessRuleException('Reported interval starts before the work order’s recorded production run.');
        }
        if ($wo->actual_end && $finish->gt($wo->actual_end)) {
            throw new BusinessRuleException('Reported interval ends after the work order’s recorded production run.');
        }
        if ($this->localWall($crew->assigned_at)->gt($start)) {
            throw new BusinessRuleException('Reported production started before this crew assignment began.');
        }
        if ($crew->ended_at && $this->localWall($crew->ended_at)->lt($finish)) {
            throw new BusinessRuleException('Reported production finished after this crew assignment ended.');
        }
    }

    private function localWall(Carbon $value): Carbon
    {
        return Carbon::parse($value->format('Y-m-d H:i:s'), config('app.timezone'));
    }

    private function crewSnapshot(WorkOrderCrew $crew): array
    {
        $crew->loadMissing('members');

        return [
            'crew_id' => $crew->hash_id,
            'recorder_employee_id' => app('hashids')->encode($crew->recorder_employee_id),
            'assigned_at' => $this->localWall($crew->assigned_at)->toIso8601String(),
            'ended_at' => $crew->ended_at ? $this->localWall($crew->ended_at)->toIso8601String() : null,
            'members' => $crew->members->map(static function ($member): array {
                $snapshot = $member->employee_snapshot ?? [];

                return [
                    'employee_id' => app('hashids')->encode($member->employee_id),
                    'employee_number' => $snapshot['employee_no'] ?? null,
                    'name' => $snapshot['name'] ?? null,
                ];
            })->values()->all(),
        ];
    }

    private function evidenceSummary(WorkOrder $wo, WorkOrderCrew $crew, Carbon $start, Carbon $finish): array
    {
        $start = $start->copy()->setTimezone(config('app.timezone'));
        $finish = $finish->copy()->setTimezone(config('app.timezone'));
        $crew->loadMissing('members');
        $attendance = $crew->members->map(function ($member) use ($start, $finish): array {
            $name = $member->employee_snapshot['name'] ?? 'Crew member';
            $firstDate = $start->copy()->subDay()->toDateString();
            $lastDate = $finish->toDateString();
            $rows = Attendance::withTrashed()->with('shift')->where('employee_id', $member->employee_id)
                ->whereBetween('date', [$firstDate, $lastDate])->orderBy('id')->get();
            $row = $rows->first(fn (Attendance $item): bool => $item->time_in && $item->time_out
                && $this->localWall($item->time_in)->lte($start) && $this->localWall($item->time_out)->gte($finish));
            if (! $row) {
                $knownConflicts = $rows->contains(function (Attendance $item) use ($start, $finish): bool {
                    $shift = $this->attendanceShiftInterval($item);
                    $shiftOverlaps = $shift && $shift['start']->lt($finish) && $shift['end']->gt($start);
                    $punchOverlaps = $item->time_in && (! $item->time_out || $this->localWall($item->time_out)->gt($start))
                        && $this->localWall($item->time_in)->lt($finish);
                    if (! $shiftOverlaps && ! $punchOverlaps) {
                        return false;
                    }

                    return $item->trashed() || in_array($item->status?->value, ['absent', 'on_leave'], true)
                        || ($item->time_in && $item->time_out)
                        || ($item->time_in && $this->localWall($item->time_in)->gt($finish))
                        || ($item->time_out && $this->localWall($item->time_out)->lt($start));
                });

                return ['name' => $name, 'state' => $knownConflicts ? 'conflicting' : 'missing'];
            }
            if ($row->trashed() || in_array($row->status?->value, ['absent', 'on_leave'], true)) {
                return ['name' => $name, 'state' => 'conflicting'];
            }

            $assignment = EmployeeShiftAssignment::query()->where('employee_id', $member->employee_id)
                ->whereDate('effective_date', '<=', $row->date->toDateString())
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $row->date->toDateString()))
                ->orderByDesc('effective_date')->first();
            if (! $assignment) {
                return ['name' => $name, 'state' => 'missing'];
            }
            if ((int) $assignment->shift_id !== (int) $row->shift_id
                || ! is_array($assignment->working_days)
                || ! in_array((int) $row->date->dayOfWeekIso, array_map('intval', $assignment->working_days), true)) {
                return ['name' => $name, 'state' => 'conflicting'];
            }
            if (! $row->shift || ! $this->shiftCoversInterval($row, $start, $finish)) {
                return ['name' => $name, 'state' => 'conflicting'];
            }

            $shiftStart = Carbon::parse($row->date->toDateString().' '.$row->shift->start_time, config('app.timezone'));
            $shiftEnd = Carbon::parse($row->date->toDateString().' '.$row->shift->end_time, config('app.timezone'));
            if ($row->shift->is_night_shift || $shiftEnd->lte($shiftStart)) {
                $shiftEnd->addDay();
            }

            return ['name' => $name, 'state' => 'verified',
                'shift' => ['starts_at' => $shiftStart->toIso8601String(), 'ends_at' => $shiftEnd->toIso8601String()]];
        })->values();
        $attendanceState = $attendance->contains('state', 'conflicting') ? 'conflicting'
            : ($attendance->contains('state', 'missing') || $attendance->isEmpty() ? 'missing' : 'verified');

        $startWall = $start->format('Y-m-d H:i:s');
        $finishWall = $finish->format('Y-m-d H:i:s');
        $schedule = ProductionSchedule::query()->where('work_order_id', $wo->id)->whereNull('rework_ncr_id')
            ->with(['machine', 'mold'])->whereIn('status', ['confirmed', 'executed'])->where('scheduled_start', '<=', $startWall)
            ->where('scheduled_end', '>=', $finishWall)->orderByDesc('id')->first();
        $resourceConflict = false;
        if ($schedule && ($schedule->machine_id || $schedule->mold_id)) {
            $resourceConflict = ProductionSchedule::query()->where('work_order_id', '!=', $wo->id)
                ->whereIn('status', ['confirmed', 'executed'])
                ->where('scheduled_start', '<', $finishWall)->where('scheduled_end', '>', $startWall)
                ->where(function ($query) use ($schedule): void {
                    if ($schedule->machine_id) {
                        $query->where('machine_id', $schedule->machine_id);
                    }
                    if ($schedule->mold_id) {
                        $schedule->machine_id
                            ? $query->orWhere('mold_id', $schedule->mold_id)
                            : $query->where('mold_id', $schedule->mold_id);
                    }
                })->exists();
        }
        // Crew assignments are captured in plant-local wall time, like the
        // historical report interval and shift schedule.
        $crewConflict = $crew->members->isNotEmpty() && WorkOrderCrew::query()->where('work_order_id', '!=', $wo->id)
            ->where('assigned_at', '<', $finishWall)->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $startWall))
            ->whereHas('members', fn ($query) => $query->whereIn('employee_id', $crew->members->pluck('employee_id')))->exists();
        $resourceState = $resourceConflict ? 'conflicting' : ($schedule ? 'verified' : 'missing');
        $executionVerified = $wo->actual_start && $wo->actual_start->lte($start) && (! $wo->actual_end || $wo->actual_end->gte($finish));

        return [
            'crew' => ['state' => $crewConflict ? 'conflicting' : 'verified', 'assigned_at' => $this->localWall($crew->assigned_at)->toIso8601String(),
                'ended_at' => $crew->ended_at ? $this->localWall($crew->ended_at)->toIso8601String() : null],
            'attendance' => ['state' => $attendanceState, 'members' => $attendance->all()],
            'execution' => ['state' => $executionVerified ? 'verified' : 'conflicting', 'source' => 'work_order_run_history'],
            'resources' => ['state' => $resourceState, 'source' => $schedule ? 'production_schedule_history' : null,
                'machine_id' => $schedule?->machine_id ? app('hashids')->encode($schedule->machine_id) : null,
                'mold_id' => $schedule?->mold_id ? app('hashids')->encode($schedule->mold_id) : null,
                'machine_code' => $schedule?->machine?->machine_code,
                'mold_code' => $schedule?->mold?->mold_code],
        ];
    }

    private function shiftCoversInterval(Attendance $attendance, Carbon $start, Carbon $finish): bool
    {
        $interval = $this->attendanceShiftInterval($attendance);
        if (! $interval) {
            return false;
        }

        return $interval['start']->lte($start) && $interval['end']->gte($finish);
    }

    /** @return array{start: Carbon, end: Carbon}|null */
    private function attendanceShiftInterval(Attendance $attendance): ?array
    {
        if (! $attendance->shift) {
            return null;
        }
        $shiftStart = Carbon::parse($attendance->date->toDateString().' '.$attendance->shift->start_time, config('app.timezone'));
        $shiftEnd = Carbon::parse($attendance->date->toDateString().' '.$attendance->shift->end_time, config('app.timezone'));
        if ($attendance->shift->is_night_shift || $shiftEnd->lte($shiftStart)) {
            $shiftEnd->addDay();
        }

        return ['start' => $shiftStart, 'end' => $shiftEnd];
    }

    private function assertNoHistoricalConflicts(array $evidence): void
    {
        foreach (['crew', 'attendance', 'execution', 'resources'] as $section) {
            if (($evidence[$section]['state'] ?? null) === 'conflicting') {
                throw new BusinessRuleException('Historical '.$section.' evidence conflicts with this report. Reconcile the production history before approval.');
            }
        }
    }

    private function assertApprover(User $approver): void
    {
        abort_unless($approver->is_active && $approver->hasPermission('production.work_orders.lifecycle')
            && $approver->hasPermission('production.wo.record'), 403);
    }

    public function approve(LateProductionReport $report, User $approver, ?string $reviewReason = null): WorkOrderOutput
    {
        $this->assertApprover($approver);

        return DB::transaction(function () use ($report, $approver, $reviewReason): WorkOrderOutput {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($report->work_order_id);
            $locked = LateProductionReport::query()->lockForUpdate()->findOrFail($report->id);
            if ($locked->status === LateProductionReportStatus::Approved) {
                if ((int) $locked->reviewed_by !== (int) $approver->id || ! $locked->output) {
                    throw new BusinessRuleException('This report was already approved by another manager.');
                }

                return $locked->output;
            }
            if ((int) $locked->recorded_by === (int) $approver->id) {
                throw new BusinessRuleException('The report submitter cannot approve their own historical production.');
            }
            if (! in_array($locked->status, [LateProductionReportStatus::Pending, LateProductionReportStatus::Correction], true)) {
                throw new BusinessRuleException('Only pending historical production reports can be approved.');
            }
            if ($wo->status === WorkOrderStatus::Cancelled) {
                throw new BusinessRuleException('Cancelled work orders cannot receive production output. Reject this exception report.');
            }
            if ($wo->status === WorkOrderStatus::Closed && trim((string) $reviewReason) === '') {
                throw new BusinessRuleException('Closed work orders require a documented correction review reason.');
            }
            if ($wo->status === WorkOrderStatus::Closed) {
                $locked->forceFill([
                    'status' => LateProductionReportStatus::Correction->value,
                    'review_reason' => trim((string) $reviewReason),
                ])->save();
            }

            $crew = WorkOrderCrew::query()->findOrFail($locked->work_order_crew_id);
            $this->assertValidInterval($wo, $crew, $locked->production_started_at, $locked->production_finished_at);
            $evidence = $this->evidenceSummary($wo, $crew, $locked->production_started_at, $locked->production_finished_at);
            $this->assertNoHistoricalConflicts($evidence);
            $missingEvidence = collect(['crew', 'attendance', 'execution', 'resources'])
                ->filter(fn (string $section): bool => ($evidence[$section]['state'] ?? null) === 'missing')->values()->all();
            if ($missingEvidence !== [] && trim((string) $reviewReason) === '') {
                throw new BusinessRuleException('Historical '.implode(', ', $missingEvidence).' evidence is incomplete. Add a review reason explaining how management reconciled the missing records.');
            }
            // Posting uses the persisted historic equipment snapshot to account mold shots.
            // Save the freshly revalidated evidence before invoking it so approval cannot
            // consume a stale submission-time resource snapshot.
            $locked->forceFill(['evidence_summary' => $evidence])->save();
            $output = app(WorkOrderOutputService::class)->postLateReport($locked, $approver);
            $locked->forceFill([
                'status' => LateProductionReportStatus::Approved->value,
                'reviewed_by' => $approver->id,
                'reviewed_at' => now()->utc(),
                'review_reason' => trim((string) $reviewReason) ?: null,
                'evidence_summary' => $evidence,
                'work_order_output_id' => $output->id,
            ])->save();

            $autoFinished = app(WorkOrderService::class)->autoFinishIfTargetReached($wo);
            $wo->refresh();

            if (! $autoFinished && $wo->usesFinishFirstQc() && in_array($wo->status, [WorkOrderStatus::Completed, WorkOrderStatus::Closed], true)) {
                app(WorkOrderFinalInspectionService::class)
                    ->ensureForCompletedWorkOrder($wo->fresh(), $approver);
            }

            return $output;
        });
    }

    public function reject(LateProductionReport $report, User $reviewer, string $reason): LateProductionReport
    {
        $this->assertApprover($reviewer);
        if ((int) $report->recorded_by === (int) $reviewer->id) {
            throw new BusinessRuleException('The report submitter cannot reject their own historical production.');
        }

        return DB::transaction(function () use ($report, $reviewer, $reason): LateProductionReport {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($report->work_order_id);
            $locked = LateProductionReport::query()->lockForUpdate()->findOrFail($report->id);
            if (! in_array($locked->status, [LateProductionReportStatus::Pending, LateProductionReportStatus::Correction], true)) {
                throw new BusinessRuleException('Only pending historical production reports can be rejected.');
            }
            $locked->forceFill([
                'status' => LateProductionReportStatus::Rejected->value,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now()->utc(),
                'review_reason' => trim($reason),
            ])->save();

            app(WorkOrderService::class)->autoFinishIfTargetReached($wo);

            return $locked;
        });
    }

    public function canApprove(LateProductionReport $report, User $actor): bool
    {
        return $actor->is_active && $actor->hasPermission('production.work_orders.lifecycle')
            && $actor->hasPermission('production.wo.record') && (int) $report->recorded_by !== (int) $actor->id
            && in_array($report->status, [LateProductionReportStatus::Pending, LateProductionReportStatus::Correction], true);
    }
}
