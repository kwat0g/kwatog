<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Exceptions\ForbiddenActionException;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionDefectivePiece;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionPieceReading;
use App\Modules\Quality\Resources\InspectionResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class WorkOrderFinalInspectionService
{
    public function __construct(private readonly InspectionService $inspections) {}

    public function ensureForCompletedWorkOrder(WorkOrder $wo, User $by): Collection
    {
        return DB::transaction(function () use ($wo, $by) {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            $this->assertWorkflow($wo);
            if (! in_array($wo->status->value, ['completed', 'closed'], true)) {
                throw new BusinessRuleException('Finish production before creating final QC.');
            }
            return $wo->outputs()->whereNull('rework_ncr_id')->where('good_count', '>', 0)->orderBy('id')->lockForUpdate()->get()
                ->map(fn (WorkOrderOutput $output) => $this->inspections->createForOutput($output, $wo, $by));
        }, 3);
    }

    public function show(WorkOrder $wo, User $by, array $page = []): array
    {
        $this->authorize($by, 'quality.inspections.view');
        $this->assertWorkflow($wo);
        $outputs = $this->outputs($wo);
        $batches = $this->batches($outputs);
        return $this->response($wo, $outputs, $batches, $page, $by);
    }

    public function saveEvidence(WorkOrder $wo, array $data, User $by): array
    {
        $this->authorize($by, 'quality.inspections.manage');
        return DB::transaction(function () use ($wo, $data, $by) {
            [$wo, $outputs, $batches] = $this->lock($wo, $data['manifest'] ?? []);
            $inspection = $batches->first(fn (Inspection $row) => $row->hash_id === ($data['inspection_id'] ?? null));
            if (! $inspection || ! in_array($inspection->status, [InspectionStatus::Draft, InspectionStatus::InProgress], true)) {
                throw new BusinessRuleException('Only draft QC evidence can change.');
            }
            if (($data['expected_result_version'] ?? null) !== (int) $inspection->result_version) {
                throw new BusinessRuleException('Inspection changed. Reload saved progress.');
            }
            $quantity = $data['defective_quantity'] ?? null;
            if (! is_int($quantity) || $quantity < 0 || $quantity > $inspection->batch_quantity) {
                throw new BusinessRuleException('Defective quantity must be a whole number within this batch.');
            }
            if ($inspection->usesConfirmedChecks() && ! empty($data['readings'])) {
                throw new BusinessRuleException('Targets are references; this inspection records confirmed checks, not numeric readings.');
            }
            $requirements = $inspection->measurements()->lockForUpdate()->get()->keyBy('hash_id');
            $pieces = $data['pieces'] ?? [];
            $readings = $data['readings'] ?? [];
            $remove = $data['remove_piece_numbers'] ?? [];
            $ordinals = collect($pieces)->pluck('piece_number')->merge(collect($readings)->pluck('piece_number'))->merge($remove)->unique();
            if ($ordinals->count() > 50) {
                throw new BusinessRuleException('Save at most 50 pieces per page.');
            }
            foreach ($ordinals as $number) {
                if (! is_int($number) || $number < 1 || $number > $inspection->batch_quantity) {
                    throw new BusinessRuleException('Piece number must belong to this batch.');
                }
            }
            if (($data['reset_defects'] ?? false) === true) {
                $inspection->defectivePieces()->delete();
            }
            $inspection->defectivePieces()->whereIn('piece_number', $remove)->delete();
            $seen = [];
            foreach ($pieces as $piece) {
                $number = $piece['piece_number'];
                $problem = $piece['problem_description'] ?? null;
                if (isset($seen[$number]) || ($problem !== null && (! is_string($problem) || trim($problem) === '' || mb_strlen($problem) > 500))) {
                    throw new BusinessRuleException('Each defective piece needs one problem description in 1–500 characters.');
                }
                $requirement = $requirements->get($piece['requirement_id'] ?? '');
                if (isset($piece['requirement_id']) && (! $inspection->usesConfirmedChecks() || ! $requirement)) {
                    throw new BusinessRuleException('Choose a failed check belonging to this inspection.');
                }
                $seen[$number] = true;
                if ($problem === null) {
                    $inspection->defectivePieces()->where('piece_number', $number)->delete();
                } else {
                    InspectionDefectivePiece::updateOrCreate(['inspection_id' => $inspection->id, 'piece_number' => $number],
                        ['problem_description' => trim($problem), 'recorded_by' => $by->id,
                            ...($inspection->usesConfirmedChecks() ? ['requirement_id' => $requirement?->id] : [])]);
                }
            }
            $requirements = $inspection->measurements()->lockForUpdate()->get()->keyBy('hash_id');
            $seen = [];
            foreach ($readings as $reading) {
                $requirement = $requirements->get($reading['requirement_id'] ?? '');
                $key = ($reading['piece_number'] ?? '').':'.($reading['requirement_id'] ?? '');
                if (! $requirement || ! $requirement->requiresSizeReading() || isset($seen[$key])) {
                    throw new BusinessRuleException('Record each required size once for this batch and piece.');
                }
                $seen[$key] = true;
                $value = $reading['measured_value'] ?? null;
                if ($value !== null && (! is_string($value) || ! preg_match('/^-?\d{1,8}(?:\.\d{1,4})?$/', $value))) {
                    throw new BusinessRuleException('Enter actual size with up to 4 decimal places.');
                }
                $identity = ['inspection_id' => $inspection->id, 'requirement_id' => $requirement->id, 'piece_number' => $reading['piece_number']];
                if ($value === null) {
                    InspectionPieceReading::where($identity)->delete();
                } else {
                    $evaluation = clone $requirement;
                    $evaluation->measured_value = $value;
                    InspectionPieceReading::updateOrCreate($identity, ['measured_value' => $value, 'is_pass' => $evaluation->evaluate(), 'recorded_by' => $by->id]);
                }
            }
            $inspection->resultAuthors()->firstOrCreate(['user_id' => $by->id]);
            $inspection->forceFill(['sample_defect_count' => $quantity, 'coverage_confirmed_at' => null,
                'inspected_quantity' => null, 'result_version' => (int) $inspection->result_version + 1,
                'status' => InspectionStatus::InProgress, 'inspector_id' => $inspection->inspector_id ?? $by->id,
                'started_at' => $inspection->started_at ?? now()])->save();
            return $this->response($wo, $outputs, $this->batches($outputs), ['inspection_id' => $inspection->hash_id, 'page' => $data['page'] ?? 1, 'defect_page' => $data['defect_page'] ?? 1], $by);
        }, 3);
    }

    public function submit(WorkOrder $wo, array $data, User $by): array
    {
        $this->authorize($by, 'quality.inspections.manage');
        return DB::transaction(function () use ($wo, $data, $by) {
            [$wo, $outputs, $batches] = $this->lock($wo, $data['manifest'] ?? []);
            $drafts = $batches->filter(fn (Inspection $row) => in_array($row->status, [InspectionStatus::Draft, InspectionStatus::InProgress], true));
            $entries = collect($data['batches'] ?? []);
            if ($drafts->isEmpty() || $entries->count() !== $drafts->count() || $entries->pluck('inspection_id')->unique()->count() !== $drafts->count()) {
                throw new BusinessRuleException('Confirm coverage for every draft batch.');
            }
            foreach ($drafts as $inspection) {
                $entry = $entries->firstWhere('inspection_id', $inspection->hash_id);
                if (! $entry || ($inspection->usesConfirmedChecks() && ($entry['checks_confirmed'] ?? null) !== true)
                    || ($entry['coverage_confirmed'] ?? null) !== true || ($entry['inspected_quantity'] ?? null) !== (int) $inspection->batch_quantity) {
                    throw new BusinessRuleException('Confirm every physical piece was inspected.');
                }
                $inspection->forceFill(['coverage_confirmed_at' => now(), 'inspected_quantity' => (int) $inspection->batch_quantity])->save();
                $this->inspections->recordFullPieceOutcome($inspection, $by);
            }
            return $this->response($wo, $outputs, $this->batches($outputs), [], $by);
        }, 3);
    }

    public function review(WorkOrder $wo, array $data, User $by): array
    {
        $this->authorize($by, 'quality.inspections.review');
        return DB::transaction(function () use ($wo, $data, $by) {
            [$wo, $outputs, $batches] = $this->lock($wo, $data['manifest'] ?? []);
            $waiting = $batches->where('status', InspectionStatus::AwaitingReview);
            if ($waiting->isEmpty() || $batches->contains(fn (Inspection $row) => in_array($row->status, [InspectionStatus::Draft, InspectionStatus::InProgress], true))) {
                throw new BusinessRuleException('Submit all draft batches before independent review.');
            }
            foreach ($waiting as $inspection) {
                if ((int) $inspection->inspector_id === (int) $by->id || $inspection->hasResultAuthor($by)) {
                    throw new ForbiddenActionException('You cannot review a batch you inspected or edited.');
                }
            }
            foreach ($waiting as $inspection) {
                $decision = $inspection->proposed_result instanceof \BackedEnum ? $inspection->proposed_result->value : (string) $inspection->proposed_result;
                $this->inspections->review($inspection, $decision, $data['remarks'] ?? null, $by, (int) $inspection->result_version, true);
            }
            return $this->response($wo, $outputs, $this->batches($outputs), [], $by);
        }, 3);
    }

    public function replaceUntouchedWithConfirmedChecks(WorkOrder $wo, array $manifest, string $reason, User $by): array
    {
        $this->authorize($by, 'quality.inspections.manage');
        if (! config('inspection.outgoing_confirmed_checks_enabled') || trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw new BusinessRuleException('Confirmed checks are unavailable or the replacement reason is missing.');
        }
        return DB::transaction(function () use ($wo, $manifest, $reason, $by): array {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            $this->assertWorkflow($wo);
            $wo->assertFinishFirstRoutingCompatible();
            if ($wo->status->value !== 'completed') {
                throw new BusinessRuleException('Only a completed work order can replace untouched final QC.');
            }
            $outputs = $this->outputs($wo, true);
            $batches = $this->batches($outputs, true);
            // An exact original manifest can resolve an already completed replacement.
            if ($this->replacementRetryMatches($manifest, $batches)) {
                return $this->response($wo, $outputs, $batches, [], $by);
            }
            [$wo, $outputs, $batches] = $this->lock($wo, $manifest);
            if (! $this->canReplaceMethod($wo, $batches)) {
                throw new BusinessRuleException('This inspection already has evidence. Continue its original method.');
            }
            foreach ($batches as $original) {
                $this->inspections->createConfirmedReplacement($original, $wo, $by, $reason);
            }
            return $this->response($wo, $outputs, $this->batches($outputs), [], $by);
        }, 3);
    }

    private function canReplaceMethod(WorkOrder $wo, Collection $batches): bool
    {
        return (bool) config('inspection.outgoing_confirmed_checks_enabled') && $wo->status->value === 'completed'
            && $batches->isNotEmpty() && $batches->every(fn ($row) => $row->isUntouchedFullPieceDraft());
    }

    private function replacementRetryMatches(array $manifest, Collection $batches): bool
    {
        if (count($manifest) !== $batches->count() || $batches->isEmpty()) {
            return false;
        }
        foreach ($batches as $index => $successor) {
            if (! $successor->usesConfirmedChecks() || ! $successor->replaces_inspection_id) {
                return false;
            }
            $original = Inspection::query()->find($successor->replaces_inspection_id);
            $entry = $manifest[$index] ?? [];
            if (! $original || $original->status !== InspectionStatus::Cancelled || count($entry) !== 5
                || ($entry['inspection_id'] ?? null) !== $original->hash_id
                || ($entry['output_id'] ?? null) !== $successor->workOrderOutput->hash_id
                || ($entry['physical_quantity'] ?? null) !== (int) $original->batch_quantity
                || ($entry['result_version'] ?? null) !== (int) $original->result_version - 1
                || ($entry['specification_revision_id'] ?? null) !== app('hashids')->encode((int) $original->inspection_spec_revision_id)) {
                return false;
            }
        }
        return true;
    }

    private function assertWorkflow(WorkOrder $wo): void
    {
        if (! $wo->usesFinishFirstQc()) {
            throw new BusinessRuleException('This work order uses its existing inspection workflow.');
        }
    }

    private function authorize(User $by, string $permission): void
    {
        if (! $by->is_active || ! $by->hasPermission($permission)) {
            throw new ForbiddenActionException('Active authorized Quality user required.');
        }
    }

    private function outputs(WorkOrder $wo, bool $lock = false): Collection
    {
        return $wo->outputs()->where('good_count', '>', 0)->where(function ($query) {
            $query->whereNull('rework_ncr_id')->orWhereIn('rework_ncr_id', \App\Modules\Quality\Models\NonConformanceReport::query()->whereNotNull('rework_finished_at')->select('id'));
        })->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
    }

    private function batches(Collection $outputs, bool $lock = false): Collection
    {
        return Inspection::query()->whereIn('work_order_output_id', $outputs->pluck('id'))->where('stage', 'outgoing')
            ->where('status', '!=', 'cancelled')->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->with(['measurements', 'resultAuthors.user', 'ncr', 'product', 'workOrderOutput.workOrder', 'inspector', 'reviewer', 'spec', 'specRevision'])->get();
    }

    private function manifest(Collection $batches): array
    {
        return $batches->map(fn (Inspection $row) => ['output_id' => $row->workOrderOutput->hash_id,
            'inspection_id' => $row->hash_id, 'physical_quantity' => (int) $row->batch_quantity,
            'result_version' => (int) $row->result_version,
            'specification_revision_id' => $row->inspection_spec_revision_id ? app('hashids')->encode((int) $row->inspection_spec_revision_id) : ''])->all();
    }

    private function lock(WorkOrder $wo, array $manifest): array
    {
        $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
        $this->assertWorkflow($wo);
        if (! in_array($wo->status->value, ['completed', 'closed'], true)) {
            throw new BusinessRuleException('Finish production before final QC.');
        }
        $outputs = $this->outputs($wo, true);
        $batches = $this->batches($outputs, true);
        if ($outputs->isEmpty() || $batches->count() !== $outputs->count() || $batches->contains(fn (Inspection $row) => ! $row->usesFullPieceCapture())) {
            throw new BusinessRuleException('Final QC handoff is incomplete. Quality must retry inspection creation.');
        }
        $expected = $this->manifest($batches);
        if (count($manifest) !== count($expected)) {
            throw new BusinessRuleException('Production changed. Reload inspection totals.');
        }
        foreach ($expected as $index => $row) {
            if (array_diff_assoc($row, $manifest[$index] ?? []) || array_diff_assoc($manifest[$index] ?? [], $row)) {
                throw new BusinessRuleException('Production changed. Reload inspection totals.');
            }
        }
        return [$wo, $outputs, $batches];
    }

    public function summariesForWorkOrders(array $workOrderIds): array
    {
        if ($workOrderIds === []) {
            return [];
        }
        $outputs = WorkOrderOutput::query()->whereIn('work_order_id', $workOrderIds)->where('good_count', '>', 0)
            ->where(fn ($query) => $query->whereNull('rework_ncr_id')->orWhereIn('rework_ncr_id',
                \App\Modules\Quality\Models\NonConformanceReport::query()->whereNotNull('rework_finished_at')->select('id')))->get();
        $batches = $this->batches($outputs);
        return $outputs->groupBy('work_order_id')->map(fn ($rows) => $this->releaseSummary($rows,
            $batches->whereIn('work_order_output_id', $rows->pluck('id'))))->all();
    }

    private function releaseSummary(Collection $outputs, Collection $batches): array
    {
        $produced = (int) $outputs->whereNull('rework_ncr_id')->sum('good_count');
        $state = $batches->contains(fn ($row) => in_array($row->status, [InspectionStatus::Draft, InspectionStatus::InProgress], true)) || $batches->isEmpty() ? 'checking'
            : ($batches->contains(fn ($row) => $row->status === InspectionStatus::AwaitingReview) ? 'awaiting_review'
            : ($batches->contains(fn ($row) => ! $row->canReleaseOutgoing()) || $batches->sum('accepted_quantity') < $produced ? 'failed' : 'released'));
        return ['produced_quantity' => $produced,
            'defective_quantity' => (int) $batches->filter(fn ($row) => $row->workOrderOutput->rework_ncr_id === null)->sum('sample_defect_count'),
            'repaired_accepted_quantity' => (int) $batches->filter(fn ($row) => $row->workOrderOutput->rework_ncr_id !== null && $row->canReleaseOutgoing())->sum('accepted_quantity'),
            'accepted_quantity' => (int) $batches->sum('accepted_quantity'), 'state' => $state];
    }

    private function response(WorkOrder $wo, Collection $outputs, Collection $batches, array $page, User $by): array
    {
        $wo->loadMissing(['product:id,part_number,name,unit_of_measure', 'salesOrder:id,so_number',
            'salesOrderItem:id,requested_delivery_date,delivery_date', 'machine:id,machine_code,name', 'mold:id,mold_code,name']);
        $perPage = max(1, min(50, (int) ($page['per_page'] ?? 50)));
        $readingPage = max(1, (int) ($page['page'] ?? 1));
        $defectPage = max(1, (int) ($page['defect_page'] ?? 1));
        $selected = $batches->first(fn (Inspection $row) => $row->hash_id === ($page['inspection_id'] ?? null)) ?? $batches->first();
        $rows = $batches->map(function (Inspection $inspection) use ($by) {
            $request = request();
            $request->setUserResolver(fn () => $by);
            $resource = (new InspectionResource($inspection))->resolve($request);
            $sizes = $inspection->measurements->filter(fn (InspectionMeasurement $row) => $row->requiresSizeReading());
            $resource['defective_quantity'] = (int) $inspection->sample_defect_count;
            $resource['described_defective_quantity'] = $inspection->defectivePieces()->count();
            $resource['checks_confirmed_at'] = $inspection->usesConfirmedChecks() ? $inspection->coverage_confirmed_at?->toISOString() : null;
            $resource['missing_readings'] = $inspection->usesConfirmedChecks() ? 0 : max(0, $sizes->count() * $inspection->batch_quantity - $inspection->pieceReadings()->whereIn('requirement_id', $sizes->pluck('id'))->count());
            return $resource;
        })->all();
        $pieces = [];
        if ($selected) {
            $start = ($readingPage - 1) * $perPage + 1;
            $end = min($selected->batch_quantity, $start + $perPage - 1);
            $readings = $selected->pieceReadings()->whereBetween('piece_number', [$start, $end])->get();
            $problems = $selected->defectivePieces()->whereBetween('piece_number', [$start, $end])->get()->keyBy('piece_number');
            $sizes = $selected->measurements->filter(fn (InspectionMeasurement $row) => $row->requiresSizeReading());
            for ($number = $start; $number <= $end; $number++) {
                $pieces[] = ['piece_number' => $number, 'problem_description' => $problems->get($number)?->problem_description,
                    'readings' => $sizes->map(function (InspectionMeasurement $size) use ($readings, $number) {
                        $reading = $readings->first(fn ($row) => $row->piece_number === $number && $row->requirement_id === $size->id);
                        return ['requirement_id' => $size->hash_id, 'measured_value' => $reading?->measured_value, 'is_pass' => $reading?->is_pass];
                    })->values()->all()];
            }
        }
        $defects = $selected?->defectivePieces()->orderBy('piece_number')->offset(($defectPage - 1) * $perPage)->limit($perPage)->get(['piece_number', 'problem_description', 'requirement_id']);
        $summary = $this->releaseSummary($outputs, $batches);
        return ['work_order_id' => $wo->hash_id, 'wo_number' => $wo->wo_number,
            'can_replace_method' => $by->hasPermission('quality.inspections.manage') && $batches->count() === $outputs->count() && $this->canReplaceMethod($wo, $batches), 'manifest' => $this->manifest($batches),
            'context' => ['product' => $wo->product ? ['part_number' => $wo->product->part_number,
                'name' => $wo->product->name, 'unit_of_measure' => $wo->product->unit_of_measure] : null,
                'sales_order' => $wo->salesOrder ? ['id' => $wo->salesOrder->hash_id, 'so_number' => $wo->salesOrder->so_number,
                    'requested_delivery_date' => optional($wo->salesOrderItem?->requested_delivery_date ?? $wo->salesOrderItem?->delivery_date)->toDateString(),
                    'current_delivery_date' => optional($wo->salesOrderItem?->delivery_date)->toDateString()] : null,
                'batch_number' => $wo->batch_number,
                'machine' => $wo->machine ? ['code' => $wo->machine->machine_code, 'name' => $wo->machine->name] : null,
                'mold' => $wo->mold ? ['code' => $wo->mold->mold_code, 'name' => $wo->mold->name] : null,
                'production_started_at' => $wo->actual_start?->toIso8601String(),
                'production_finished_at' => $wo->actual_end?->toIso8601String(),
                'production_due_at' => $wo->planned_end?->toIso8601String(), 'timezone' => config('app.timezone'),
                'output_batches' => $batches->map(fn (Inspection $row) => ['inspection_id' => $row->hash_id,
                    'batch_code' => $row->workOrderOutput?->batch_code])->all()],
            ...$summary,
            'blocker' => $batches->count() !== $outputs->count() || $outputs->isEmpty() ? 'Final QC handoff pending. Quality must retry inspection creation.' : null,
            'batches' => $rows, 'evidence_page' => ['inspection_id' => $selected?->hash_id, 'page' => $readingPage, 'per_page' => $perPage,
                'total_pieces' => (int) $selected?->batch_quantity, 'pieces' => $pieces],
            'defect_page' => ['page' => $defectPage, 'per_page' => $perPage, 'total' => $selected?->defectivePieces()->count() ?? 0, 'pieces' => $defects?->map(fn ($piece) => ['piece_number' => $piece->piece_number, 'problem_description' => $piece->problem_description,
                    'requirement_id' => $piece->requirement_id ? app('hashids')->encode((int) $piece->requirement_id) : null])->all() ?? []]];
    }
}
