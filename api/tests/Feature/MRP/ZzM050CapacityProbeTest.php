<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\CapacityPlanningService;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M050 capacity-scheduling audit probe. ONE measurement per test — a 22003
 * overflow or an aborted transaction in a shared loop cascades every later
 * assertion into a false 500.
 *
 * Scratch file: delete before release.
 */
class ZzM050CapacityProbeTest extends TestCase
{
    use RefreshDatabase;

    private CapacityPlanningService $planner;
    private User $user;
    private Product $product;
    private Carbon $tomorrow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = app(CapacityPlanningService::class);
        $this->user = User::factory()->create();
        $this->product = $this->makeProduct('P');
        $this->tomorrow = Carbon::tomorrow()->startOfDay();
    }

    // ── INVARIANT 1: booked hours cannot exceed declared capacity ────────

    public function test_probe_booked_hours_vs_declared_available_hours_per_day(): void
    {
        // Machine declares 8.0 available hours per day.
        $machine = $this->machine('CAP-H1', 100, 'idle', 8.0);
        $mold = $this->mold('CAP-HM1', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);

        // Each WO: 800 pcs / 100 per hour = 8h + 10min setup = 8h10m = 490 min.
        $ids = [];
        foreach ([1, 2, 3] as $n) {
            $wo = $this->workOrder("CAP-H1-{$n}", WorkOrderStatus::Planned, '800', 5);
            $wo->forceFill(['planned_start' => $this->tomorrow->copy()->setTime(8, 0)])->save();
            $ids[] = $wo->id;
        }

        $result = $this->planner->run($ids);

        // Independent SQL: booked minutes per calendar day on this machine.
        $perDay = DB::select(
            "select scheduled_start::date as d,
                    sum(extract(epoch from (scheduled_end - scheduled_start))/60)::numeric as mins
               from production_schedules
              where machine_id = ? and status in ('pending','confirmed','executed')
              group by 1 order by 1",
            [$machine->id]
        );
        $declaredMinutes = (float) $machine->available_hours_per_day * 60.0; // 480

        $lines = array_map(
            static fn ($r): string => $r->d . '=' . round((float) $r->mins) . 'min',
            $perDay
        );
        $maxBooked = 0.0;
        foreach ($perDay as $r) {
            $maxBooked = max($maxBooked, (float) $r->mins);
        }

        fwrite(STDERR, "\n[M050-P1] scheduled=" . count($result['scheduled'])
            . ' conflicts=' . count($result['conflicts'])
            . ' declared_min_per_day=' . $declaredMinutes
            . ' per_day=[' . implode(', ', $lines) . ']'
            . ' max_booked_min=' . round($maxBooked) . "\n");

        $this->assertLessThanOrEqual(
            $declaredMinutes,
            $maxBooked,
            'A machine declaring available_hours_per_day must not be booked beyond it.'
        );
    }

    public function test_probe_available_hours_per_day_is_never_read_by_the_planner(): void
    {
        $src = file_get_contents(app_path('Modules/MRP/Services/CapacityPlanningService.php'));
        fwrite(STDERR, "\n[M050-P1b] planner mentions available_hours_per_day: "
            . (str_contains((string) $src, 'available_hours_per_day') ? 'YES' : 'NO') . "\n");
        $this->assertStringContainsString(
            'available_hours_per_day',
            (string) $src,
            'The finite-capacity planner must consult the declared machine capacity.'
        );
    }

    public function test_probe_no_capacity_in_horizon_reason_is_reachable(): void
    {
        // A machine+mold pair exists, so placeWorkOrder returns on the first
        // machine unconditionally. Assert the "out of capacity" branch can fire
        // for a workload that plainly exceeds any sane horizon.
        $machine = $this->machine('CAP-HZ', 100, 'idle', 8.0);
        $mold = $this->mold('CAP-HZM', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);

        $wo = $this->workOrder('CAP-HZ-1', WorkOrderStatus::Planned, '1000000', 5);
        $wo->forceFill([
            'planned_start' => $this->tomorrow->copy()->setTime(8, 0),
            'planned_end'   => $this->tomorrow->copy()->setTime(17, 0),
        ])->save();

        $result = $this->planner->run([$wo->id]);
        $row = ProductionSchedule::where('work_order_id', $wo->id)->first();

        fwrite(STDERR, "\n[M050-P1c] 1,000,000 pcs on one 8h/day machine -> scheduled="
            . count($result['scheduled']) . ' conflicts=' . count($result['conflicts'])
            . ' end=' . ($row?->scheduled_end?->toDateTimeString() ?? 'none')
            . ' planned_end=' . $wo->planned_end->toDateTimeString() . "\n");

        $this->assertCount(1, $result['conflicts'], 'A workload far beyond the horizon must conflict, not be promised.');
    }

    public function test_probe_schedule_respects_the_work_order_planned_end_due_date(): void
    {
        $machine = $this->machine('CAP-DUE', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-DUEM', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);

        $wo = $this->workOrder('CAP-DUE-1', WorkOrderStatus::Planned, '10000', 5);
        $wo->forceFill([
            'planned_start' => $this->tomorrow->copy()->setTime(8, 0),
            'planned_end'   => $this->tomorrow->copy()->setTime(17, 0),
        ])->save();

        $result = $this->planner->run([$wo->id]);
        $row = ProductionSchedule::where('work_order_id', $wo->id)->first();

        fwrite(STDERR, "\n[M050-P1d] planned_end=" . $wo->planned_end->toDateTimeString()
            . ' scheduled=' . count($result['scheduled'])
            . ' conflicts=' . count($result['conflicts'])
            . ' row=' . ($row?->scheduled_end?->toDateTimeString() ?? 'none') . "\n");

        $this->assertNull(
            $row,
            'A workload that cannot finish before the work-order due date must be refused, not promised.'
        );
    }

    // ── INVARIANT 2: two WOs on one machine at overlapping times ─────────

    public function test_probe_two_work_orders_cannot_overlap_on_one_machine_via_run(): void
    {
        $machine = $this->machine('CAP-O1', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-OM1', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);

        $a = $this->workOrder('CAP-O1-A', WorkOrderStatus::Planned, '100', 5);
        $b = $this->workOrder('CAP-O1-B', WorkOrderStatus::Planned, '100', 5);
        $this->planner->run([$a->id, $b->id]);

        $overlaps = DB::selectOne(
            "select count(*) as c
               from production_schedules a join production_schedules b
                 on a.machine_id = b.machine_id and a.id < b.id
              where a.status in ('pending','confirmed','executed')
                and b.status in ('pending','confirmed','executed')
                and a.scheduled_start < b.scheduled_end
                and b.scheduled_start < a.scheduled_end"
        );
        fwrite(STDERR, "\n[M050-P2] machine overlap pairs after run = {$overlaps->c}\n");
        $this->assertSame(0, (int) $overlaps->c);
    }

    public function test_probe_database_rejects_two_overlapping_rows_on_one_machine(): void
    {
        $machine = $this->machine('CAP-O2', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-OM2', $this->product);
        $a = $this->workOrder('CAP-O2-A', WorkOrderStatus::Planned, '100', 5);
        $b = $this->workOrder('CAP-O2-B', WorkOrderStatus::Planned, '100', 5);

        $this->rawSchedule($a, $machine, $mold, 8, 12, 'confirmed');
        $thrown = null;
        // The expected violation aborts the surrounding test transaction in
        // PostgreSQL; nest it inside a savepoint and roll back so the count
        // below can still run.
        DB::beginTransaction();
        try {
            $this->rawSchedule($b, $machine, $mold, 9, 11, 'confirmed');
        } catch (\Throwable $e) {
            $thrown = $e::class;
        }
        DB::rollBack();

        $count = ProductionSchedule::where('machine_id', $machine->id)->count();
        fwrite(STDERR, "\n[M050-P2b] direct overlapping insert threw=" . ($thrown ?? 'nothing')
            . " rows_on_machine={$count}\n");

        $this->assertNotNull(
            $thrown,
            'The database has no exclusion constraint on (machine_id, time range) — overlap is application-only.'
        );
    }

    // ── INVARIANT 3: one mold on two machines at once ────────────────────

    public function test_probe_one_mold_cannot_be_booked_on_two_machines_at_once(): void
    {
        $mA = $this->machine('CAP-MM-A', 100, 'idle', 16.0);
        $mB = $this->machine('CAP-MM-B', 200, 'idle', 16.0);
        $shared = $this->mold('CAP-MM-S', $this->product);
        $other = $this->mold('CAP-MM-O', $this->product);
        $shared->compatibleMachines()->sync([$mA->id, $mB->id]);
        $other->compatibleMachines()->sync([$mB->id]);

        $a = $this->workOrder('CAP-MM-1', WorkOrderStatus::Planned, '100', 5);
        $b = $this->workOrder('CAP-MM-2', WorkOrderStatus::Planned, '100', 5);

        // Row 1: shared mold on machine A, 08:00–12:00.
        $this->rawSchedule($a, $mA, $shared, 8, 12, 'confirmed');
        // Row 2: other mold on machine B, 09:00–11:00 (pending, so reassignable).
        $row2 = $this->rawSchedule($b, $mB, $other, 9, 11, 'pending');

        // Reassign row 2 onto machine B with the SHARED mold: the mold is now
        // physically required on two machines during 09:00–11:00.
        $thrown = null;
        try {
            $this->planner->reassign($row2->id, $mB->id, $shared->id);
        } catch (\Throwable $e) {
            $thrown = $e->getMessage();
        }

        $moldOverlaps = DB::selectOne(
            "select count(*) as c
               from production_schedules a join production_schedules b
                 on a.mold_id = b.mold_id and a.id < b.id and a.machine_id <> b.machine_id
              where a.status in ('pending','confirmed','executed')
                and b.status in ('pending','confirmed','executed')
                and a.scheduled_start < b.scheduled_end
                and b.scheduled_start < a.scheduled_end"
        );
        fwrite(STDERR, "\n[M050-P3] reassign threw=" . ($thrown ?? 'nothing')
            . " mold_double_booked_pairs={$moldOverlaps->c}\n");

        $this->assertSame(0, (int) $moldOverlaps->c, 'One physical mold cannot run on two machines simultaneously.');
    }

    public function test_probe_run_does_not_double_book_one_mold_across_machines(): void
    {
        $mA = $this->machine('CAP-M3-A', 100, 'idle', 16.0);
        $mB = $this->machine('CAP-M3-B', 100, 'idle', 16.0);
        $shared = $this->mold('CAP-M3-S', $this->product);
        $shared->compatibleMachines()->sync([$mA->id, $mB->id]);

        // Pre-book machine A fully so the planner is pushed onto machine B,
        // while machine A's window is occupied by the same mold.
        $blocker = $this->workOrder('CAP-M3-BLK', WorkOrderStatus::Confirmed, '100', 9);
        $blocker->forceFill(['machine_id' => $mA->id, 'mold_id' => $shared->id])->save();
        $this->rawSchedule($blocker, $mA, $shared, 8, 12, 'confirmed');

        $wo = $this->workOrder('CAP-M3-1', WorkOrderStatus::Planned, '100', 5);
        $wo->forceFill(['planned_start' => $this->tomorrow->copy()->setTime(9, 0)])->save();
        $this->planner->run([$wo->id]);

        $row = ProductionSchedule::where('work_order_id', $wo->id)->first();
        $moldOverlaps = DB::selectOne(
            "select count(*) as c
               from production_schedules a join production_schedules b
                 on a.mold_id = b.mold_id and a.id < b.id
              where a.status in ('pending','confirmed','executed')
                and a.scheduled_start < b.scheduled_end
                and b.scheduled_start < a.scheduled_end"
        );
        fwrite(STDERR, "\n[M050-P3b] run placed on machine=" . ($row?->machine_id ?? 'none')
            . ' (A=' . $mA->id . ' B=' . $mB->id . ') start=' . ($row?->scheduled_start?->toDateTimeString() ?? '-')
            . " mold_overlap_pairs={$moldOverlaps->c}\n");
        $this->assertSame(0, (int) $moldOverlaps->c);
    }

    // ── INVARIANT 4: inactive / archived / under-maintenance machine ─────

    public function test_probe_run_refuses_a_machine_under_maintenance(): void
    {
        $machine = $this->machine('CAP-MT1', 100, 'maintenance', 16.0);
        $mold = $this->mold('CAP-MTM1', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-MT1-1', WorkOrderStatus::Planned, '100', 5);

        $result = $this->planner->run([$wo->id]);
        fwrite(STDERR, "\n[M050-P4a] maintenance machine -> scheduled=" . count($result['scheduled'])
            . ' conflicts=' . count($result['conflicts']) . "\n");
        $this->assertCount(0, $result['scheduled']);
    }

    public function test_probe_run_refuses_a_machine_in_breakdown(): void
    {
        $machine = $this->machine('CAP-BD1', 100, 'breakdown', 16.0);
        $mold = $this->mold('CAP-BDM1', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-BD1-1', WorkOrderStatus::Planned, '100', 5);

        $result = $this->planner->run([$wo->id]);
        fwrite(STDERR, "\n[M050-P4b] breakdown machine -> scheduled=" . count($result['scheduled'])
            . ' conflicts=' . count($result['conflicts']) . "\n");
        $this->assertCount(0, $result['scheduled']);
    }

    public function test_probe_reassign_refuses_a_machine_under_maintenance(): void
    {
        $ok = $this->machine('CAP-MT2-OK', 100, 'idle', 16.0);
        $bad = $this->machine('CAP-MT2-BAD', 100, 'maintenance', 16.0);
        $mold = $this->mold('CAP-MTM2', $this->product);
        $mold->compatibleMachines()->sync([$ok->id, $bad->id]);
        $wo = $this->workOrder('CAP-MT2-1', WorkOrderStatus::Planned, '100', 5);
        $row = $this->rawSchedule($wo, $ok, $mold, 8, 10, 'pending');

        $thrown = null;
        try {
            $this->planner->reassign($row->id, $bad->id, $mold->id);
        } catch (\Throwable $e) {
            $thrown = $e->getMessage();
        }
        fwrite(STDERR, "\n[M050-P4c] reassign to maintenance machine threw=" . ($thrown ?? 'nothing') . "\n");
        $this->assertNotNull($thrown);
    }

    public function test_probe_confirmed_schedule_is_handled_when_machine_breaks_down(): void
    {
        $machine = $this->machine('CAP-BD2', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-BDM2', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-BD2-1', WorkOrderStatus::Planned, '100', 5);
        $this->planner->run([$wo->id]);
        $row = ProductionSchedule::where('work_order_id', $wo->id)->firstOrFail();
        $this->planner->confirm([$row->id], $this->user->id);

        // Machine goes down after the commitment was made.
        $machine->forceFill(['status' => 'breakdown'])->save();

        $after = $row->fresh();
        fwrite(STDERR, "\n[M050-P4d] after machine breakdown: schedule status="
            . $after->status->value . ' machine_status=' . $machine->fresh()->status->value . "\n");
        $this->assertNotSame(
            ProductionScheduleStatus::Confirmed,
            $after->status,
            'A confirmed commitment on a broken machine must be re-planned or flagged, not left standing.'
        );
    }

    public function test_probe_soft_deleted_machine_still_blocks_capacity_but_vanishes_from_the_gantt(): void
    {
        $machine = $this->machine('CAP-SD1', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-SDM1', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-SD1-1', WorkOrderStatus::Planned, '100', 5);
        $row = $this->rawSchedule($wo, $machine, $mold, 8, 12, 'confirmed');

        $machine->delete(); // soft delete

        $snap = $this->planner->snapshot(
            $this->tomorrow->copy()->startOfDay(),
            $this->tomorrow->copy()->endOfDay(),
        );
        $barsShown = 0;
        foreach ($snap['rows'] as $r) {
            $barsShown += count($r['bars']);
        }
        $persisted = ProductionSchedule::whereIn('status', ['pending', 'confirmed', 'executed'])
            ->whereBetween('scheduled_start', [$this->tomorrow->copy()->startOfDay(), $this->tomorrow->copy()->endOfDay()])
            ->count();

        fwrite(STDERR, "\n[M050-P4e] soft-deleted machine: gantt_bars={$barsShown} persisted_active_rows={$persisted}"
            . ' schedule_row_status=' . $row->fresh()->status->value . "\n");
        $this->assertSame(
            $persisted,
            $barsShown,
            'The Gantt must render every persisted active schedule; a soft-deleted machine hides real commitments.'
        );
    }

    // ── INVARIANT 5: scheduling into the past ───────────────────────────

    public function test_probe_scheduling_into_the_past_is_refused(): void
    {
        $machine = $this->machine('CAP-PAST', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-PASTM', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);

        $wo = $this->workOrder('CAP-PAST-1', WorkOrderStatus::Planned, '100', 5);
        $wo->forceFill([
            'planned_start' => Carbon::now()->subDays(30)->setTime(8, 0),
            'planned_end'   => Carbon::now()->subDays(29)->setTime(17, 0),
        ])->save();

        $result = $this->planner->run([$wo->id]);
        $row = ProductionSchedule::where('work_order_id', $wo->id)->first();

        fwrite(STDERR, "\n[M050-P5] now=" . Carbon::now()->toDateTimeString()
            . ' scheduled_start=' . ($row?->scheduled_start?->toDateTimeString() ?? 'none')
            . ' scheduled=' . count($result['scheduled']) . "\n");

        $this->assertTrue(
            $row === null || $row->scheduled_start->greaterThanOrEqualTo(Carbon::now()->startOfDay()),
            'A capacity plan must not book a machine window that has already elapsed.'
        );
    }

    // ── INVARIANT 6: mold rated shot life ───────────────────────────────

    public function test_probe_mold_shot_life_accumulates_across_one_run(): void
    {
        $machine = $this->machine('CAP-SH1', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-SHM1', $this->product);
        $mold->forceFill(['current_shot_count' => 0, 'max_shots_before_maintenance' => 1000])->save();
        $mold->compatibleMachines()->sync([$machine->id]);

        $ids = [];
        foreach ([1, 2, 3] as $n) {
            $wo = $this->workOrder("CAP-SH1-{$n}", WorkOrderStatus::Planned, '900', 5);
            $ids[] = $wo->id;
        }
        $result = $this->planner->run($ids);

        $booked = (int) DB::table('production_schedules')
            ->join('work_orders', 'work_orders.id', '=', 'production_schedules.work_order_id')
            ->where('production_schedules.mold_id', $mold->id)
            ->whereIn('production_schedules.status', ['pending', 'confirmed', 'executed'])
            ->sum('work_orders.quantity_target');
        $remaining = 1000 - 0;

        fwrite(STDERR, "\n[M050-P6a] mold remaining_shots={$remaining} booked_shots={$booked}"
            . ' scheduled=' . count($result['scheduled']) . ' conflicts=' . count($result['conflicts']) . "\n");

        $this->assertLessThanOrEqual(
            $remaining,
            $booked,
            'Total shots booked on a mold in one run must not exceed its remaining rated life.'
        );
    }

    public function test_probe_run_refuses_a_mold_past_its_rated_shot_life(): void
    {
        $machine = $this->machine('CAP-SH2', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-SHM2', $this->product);
        $mold->forceFill(['current_shot_count' => 1050, 'max_shots_before_maintenance' => 1000])->save();
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-SH2-1', WorkOrderStatus::Planned, '100', 5);

        $result = $this->planner->run([$wo->id]);
        fwrite(STDERR, "\n[M050-P6b] over-life mold via run -> scheduled=" . count($result['scheduled'])
            . ' conflicts=' . json_encode($result['conflicts']) . "\n");
        $this->assertCount(0, $result['scheduled']);
    }

    public function test_probe_reassign_refuses_a_mold_past_its_rated_shot_life(): void
    {
        $machine = $this->machine('CAP-SH3', 100, 'idle', 16.0);
        $good = $this->mold('CAP-SHM3-G', $this->product);
        $dead = $this->mold('CAP-SHM3-D', $this->product);
        $dead->forceFill(['current_shot_count' => 1050, 'max_shots_before_maintenance' => 1000])->save();
        $good->compatibleMachines()->sync([$machine->id]);
        $dead->compatibleMachines()->sync([$machine->id]);

        $wo = $this->workOrder('CAP-SH3-1', WorkOrderStatus::Planned, '100', 5);
        $row = $this->rawSchedule($wo, $machine, $good, 8, 10, 'pending');

        $thrown = null;
        try {
            $this->planner->reassign($row->id, $machine->id, $dead->id);
        } catch (\Throwable $e) {
            $thrown = $e->getMessage();
        }
        fwrite(STDERR, "\n[M050-P6c] reassign to over-life mold threw=" . ($thrown ?? 'nothing')
            . ' mold_now=' . $row->fresh()->mold_id . ' (dead=' . $dead->id . ")\n");
        $this->assertNotNull($thrown, 'Reassignment must not move work onto a mold past its rated shot life.');
    }

    public function test_probe_confirm_refuses_a_mold_past_its_rated_shot_life(): void
    {
        $machine = $this->machine('CAP-SH4', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-SHM4', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-SH4-1', WorkOrderStatus::Planned, '100', 5);
        $this->planner->run([$wo->id]);
        $row = ProductionSchedule::where('work_order_id', $wo->id)->firstOrFail();

        // Mold burns through its life between proposal and confirmation.
        $mold->forceFill(['current_shot_count' => 999999, 'max_shots_before_maintenance' => 1000])->save();

        $thrown = null;
        try {
            $this->planner->confirm([$row->id], $this->user->id);
        } catch (\Throwable $e) {
            $thrown = $e->getMessage();
        }
        fwrite(STDERR, "\n[M050-P6d] confirm with exhausted mold threw=" . ($thrown ?? 'nothing')
            . ' schedule_status=' . $row->fresh()->status->value . "\n");
        $this->assertNotNull($thrown, 'Confirmation must re-check mold shot life, not trust the proposal.');
    }

    // ── INVARIANT 7: Gantt payload == persisted plan ─────────────────────

    public function test_probe_gantt_includes_a_bar_that_starts_before_the_window(): void
    {
        $machine = $this->machine('CAP-G1', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-GM1', $this->product);
        $wo = $this->workOrder('CAP-G1-1', WorkOrderStatus::Planned, '100', 5);
        // Runs 06:00 -> 14:00 tomorrow.
        $this->rawSchedule($wo, $machine, $mold, 6, 14, 'confirmed');

        // The UI asks for the working day 08:00 -> 18:00.
        $from = $this->tomorrow->copy()->setTime(8, 0);
        $to = $this->tomorrow->copy()->setTime(18, 0);
        $snap = $this->planner->snapshot($from, $to);

        $bars = 0;
        foreach ($snap['rows'] as $r) {
            $bars += count($r['bars']);
        }
        // Independent recompute: rows overlapping the window (half-open).
        $expected = (int) DB::table('production_schedules')
            ->whereIn('status', ['pending', 'confirmed', 'executed'])
            ->where('scheduled_start', '<', $to)
            ->where('scheduled_end', '>', $from)
            ->count();

        fwrite(STDERR, "\n[M050-P7] gantt_bars={$bars} overlapping_persisted={$expected}\n");
        $this->assertSame(
            $expected,
            $bars,
            'A job already running when the window opens must still occupy the Gantt row.'
        );
    }

    public function test_probe_gantt_reversed_window_is_rejected(): void
    {
        $machine = $this->machine('CAP-G2', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-GM2', $this->product);
        $wo = $this->workOrder('CAP-G2-1', WorkOrderStatus::Planned, '100', 5);
        $this->rawSchedule($wo, $machine, $mold, 8, 12, 'confirmed');

        $thrown = null;
        $bars = null;
        try {
            $snap = $this->planner->snapshot(
                $this->tomorrow->copy()->setTime(18, 0),
                $this->tomorrow->copy()->setTime(6, 0),
            );
            $bars = 0;
            foreach ($snap['rows'] as $r) {
                $bars += count($r['bars']);
            }
        } catch (\Throwable $e) {
            $thrown = $e::class;
        }
        fwrite(STDERR, "\n[M050-P7b] reversed window threw=" . ($thrown ?? 'nothing') . " bars={$bars}\n");
        $this->assertNotNull($thrown, 'from > to must be refused, not silently return an empty schedule.');
    }

    // ── INVARIANT 8: reorder actually reorders ───────────────────────────

    public function test_probe_reorder_changes_the_scheduled_sequence(): void
    {
        $machine = $this->machine('CAP-R1', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-RM1', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);

        $a = $this->workOrder('CAP-R1-A', WorkOrderStatus::Planned, '100', 5);
        $b = $this->workOrder('CAP-R1-B', WorkOrderStatus::Planned, '100', 5);
        $this->planner->run([$a->id, $b->id]);

        $first = ProductionSchedule::orderBy('scheduled_start')->firstOrFail();
        $last = ProductionSchedule::orderByDesc('scheduled_start')->firstOrFail();
        $startBefore = $last->scheduled_start->toDateTimeString();

        // Ask for the later job to be run first.
        $this->planner->reorder($last->id, 0);
        $this->planner->reorder($first->id, 999);

        $lastAfter = $last->fresh();
        fwrite(STDERR, "\n[M050-P8] reorder: priority_order={$lastAfter->priority_order}"
            . " scheduled_start before={$startBefore} after=" . $lastAfter->scheduled_start->toDateTimeString() . "\n");

        $this->assertNotSame(
            $startBefore,
            $lastAfter->scheduled_start->toDateTimeString(),
            'reorder() writes priority_order but nothing recomputes the timeline from it.'
        );
    }

    // ── INVARIANT 9: reschedule after start / completion ────────────────

    public function test_probe_reassign_a_confirmed_schedule(): void
    {
        [$row, $altMachine, $mold] = $this->confirmedRowWithAlternate('CAP-RS1');
        $thrown = null;
        try {
            $this->planner->reassign($row->id, $altMachine->id, $mold->id);
        } catch (\Throwable $e) {
            $thrown = $e::class . ': ' . $e->getMessage();
        }
        fwrite(STDERR, "\n[M050-P9a] reassign CONFIRMED row -> " . ($thrown ?? 'ACCEPTED') . "\n");
        $this->assertNotNull($thrown);
    }

    public function test_probe_reorder_a_confirmed_schedule(): void
    {
        [$row] = $this->confirmedRowWithAlternate('CAP-RS2');
        $thrown = null;
        try {
            $this->planner->reorder($row->id, 7);
        } catch (\Throwable $e) {
            $thrown = $e::class;
        }
        fwrite(STDERR, "\n[M050-P9b] reorder CONFIRMED row -> " . ($thrown ?? 'ACCEPTED') . "\n");
        $this->assertNotNull($thrown);
    }

    public function test_probe_rerun_after_confirmation_leaves_no_orphan(): void
    {
        $machine = $this->machine('CAP-RS3', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-RSM3', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-RS3-1', WorkOrderStatus::Planned, '100', 5);
        $this->planner->run([$wo->id]);
        $row = ProductionSchedule::where('work_order_id', $wo->id)->firstOrFail();
        $this->planner->confirm([$row->id], $this->user->id);

        // Re-run the scheduler for the same WO: it is no longer 'planned'.
        $result = $this->planner->run([$wo->id]);
        $count = ProductionSchedule::where('work_order_id', $wo->id)->count();
        fwrite(STDERR, "\n[M050-P9c] rerun after confirm: scheduled=" . count($result['scheduled'])
            . ' conflicts=' . count($result['conflicts']) . " rows_for_wo={$count}\n");
        $this->assertSame(1, $count);
    }

    public function test_probe_completing_a_work_order_closes_its_schedule_row(): void
    {
        $machine = $this->machine('CAP-EX1', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-EXM1', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-EX1-1', WorkOrderStatus::Planned, '100', 5);
        $this->planner->run([$wo->id]);
        $row = ProductionSchedule::where('work_order_id', $wo->id)->firstOrFail();
        $this->planner->confirm([$row->id], $this->user->id);

        $svc = app(\App\Modules\Production\Services\WorkOrderService::class);
        $svc->start($wo->fresh(), $this->user->id);
        $started = $row->fresh();
        $svc->complete($wo->fresh(), ['quantity_produced' => 100, 'quantity_rejected' => 0]);
        $completed = $row->fresh();

        fwrite(STDERR, "\n[M050-P9d] schedule status after WO start=" . $started->status->value
            . ' after WO complete=' . $completed->status->value
            . ' wo=' . $wo->fresh()->status->value . "\n");

        $this->assertSame(
            ProductionScheduleStatus::Executed,
            $completed->status,
            'A completed work order must retire its schedule row; otherwise it keeps blocking machine capacity forever.'
        );
    }

    public function test_probe_cancelling_a_work_order_releases_its_schedule_window(): void
    {
        $machine = $this->machine('CAP-CX1', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-CXM1', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder('CAP-CX1-1', WorkOrderStatus::Planned, '100', 5);
        $this->planner->run([$wo->id]);
        $row = ProductionSchedule::where('work_order_id', $wo->id)->firstOrFail();
        $this->planner->confirm([$row->id], $this->user->id);

        app(\App\Modules\Production\Services\WorkOrderService::class)
            ->cancel($wo->fresh(), 'audit probe');

        $after = $row->fresh();
        fwrite(STDERR, "\n[M050-P9e] wo=" . $wo->fresh()->status->value
            . ' schedule=' . ($after?->status->value ?? 'deleted') . "\n");
        $this->assertTrue(
            $after === null || $after->status !== ProductionScheduleStatus::Confirmed,
            'A cancelled work order must release the machine window it reserved.'
        );
    }

    // ── INVARIANT 10: plan immutable after start ─────────────────────────

    public function test_probe_started_schedule_cannot_be_mutated_by_eloquent(): void
    {
        $row = $this->startedRow('CAP-IM1');
        $row->update(['scheduled_start' => $this->tomorrow->copy()->addDays(9)->setTime(8, 0)]);
        $after = $row->fresh();
        fwrite(STDERR, "\n[M050-P10a] eloquent update of an in-progress schedule -> start="
            . $after->scheduled_start->toDateTimeString() . "\n");
        $this->assertSame(
            $this->tomorrow->copy()->setTime(8, 0)->toDateTimeString(),
            $after->scheduled_start->toDateTimeString(),
            'The plan of a running work order is an operational record.'
        );
    }

    public function test_probe_started_schedule_cannot_be_mutated_by_raw_sql(): void
    {
        $row = $this->startedRow('CAP-IM2');
        $thrown = null;
        try {
            DB::statement('update production_schedules set scheduled_end = scheduled_end + interval \'99 days\' where id = ?', [$row->id]);
        } catch (\Throwable $e) {
            $thrown = $e::class;
        }
        fwrite(STDERR, "\n[M050-P10b] raw SQL update of an in-progress schedule threw=" . ($thrown ?? 'nothing') . "\n");
        $this->assertNotNull($thrown);
    }

    public function test_probe_started_schedule_cannot_be_deleted(): void
    {
        $row = $this->startedRow('CAP-IM3');
        $id = $row->id;
        $thrown = null;
        try {
            $row->delete();
        } catch (\Throwable $e) {
            $thrown = $e::class;
        }
        $exists = ProductionSchedule::find($id) !== null;
        fwrite(STDERR, "\n[M050-P10c] delete of an in-progress schedule threw=" . ($thrown ?? 'nothing')
            . ' still_exists=' . ($exists ? 'yes' : 'NO') . "\n");
        $this->assertTrue($exists, 'A schedule for a running work order must not be destroyable.');
    }

    public function test_probe_pg_trigger_count_on_production_schedules(): void
    {
        $rows = DB::select("select tgname from pg_trigger where not tgisinternal and tgrelid = 'production_schedules'::regclass");
        fwrite(STDERR, "\n[M050-P10d] pg_trigger on production_schedules = " . count($rows)
            . ' [' . implode(',', array_map(static fn ($r) => $r->tgname, $rows)) . "]\n");
        $this->assertGreaterThan(0, count($rows), 'audit_logs is trigger-protected in this codebase; the plan is not.');
    }

    // ── INVARIANT 11: full transition matrix ────────────────────────────

    public function test_probe_transition_matrix_over_service_entry_points(): void
    {
        $machine = $this->machine('CAP-TM', 100, 'idle', 16.0);
        $mold = $this->mold('CAP-TMM', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);

        $statuses = ['pending', 'confirmed', 'superseded', 'executed'];
        $verbs = ['confirm', 'reorder', 'reassign'];
        $matrix = [];
        $hour = 0;

        foreach ($statuses as $status) {
            foreach ($verbs as $verb) {
                // 12 cells × +2h must never wrap onto an already-used hour:
                // the 0479 exclusion constraint rejects exact-window repeats.
                $hour = ($hour + 2) % 24;
                $wo = $this->workOrder('CAP-TM-' . substr(uniqid(), -6), WorkOrderStatus::Planned, '100', 5);
                $row = $this->rawSchedule($wo, $machine, $mold, $hour, $hour + 1, $status);
                $outcome = 'ACCEPTED';
                try {
                    match ($verb) {
                        'confirm'  => $this->planner->confirm([$row->id], $this->user->id),
                        'reorder'  => $this->planner->reorder($row->id, 3),
                        'reassign' => $this->planner->reassign($row->id, $machine->id, $mold->id),
                    };
                } catch (\Throwable $e) {
                    $outcome = 'REFUSED(' . class_basename($e) . ')';
                }
                $matrix[] = "{$status}/{$verb}={$outcome}";
            }
        }
        fwrite(STDERR, "\n[M050-P11] " . count($matrix) . " cells: " . implode(' | ', $matrix) . "\n");
        $this->assertCount(12, $matrix);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @return array{0: ProductionSchedule, 1: Machine, 2: Mold} */
    private function confirmedRowWithAlternate(string $tag): array
    {
        $machine = $this->machine($tag . '-A', 100, 'idle', 16.0);
        $alt = $this->machine($tag . '-B', 200, 'idle', 16.0);
        $mold = $this->mold($tag . '-M', $this->product);
        $mold->compatibleMachines()->sync([$machine->id, $alt->id]);
        $wo = $this->workOrder($tag . '-1', WorkOrderStatus::Planned, '100', 5);
        $this->planner->run([$wo->id]);
        $row = ProductionSchedule::where('work_order_id', $wo->id)->firstOrFail();
        $this->planner->confirm([$row->id], $this->user->id);
        return [$row->fresh(), $alt, $mold];
    }

    private function startedRow(string $tag): ProductionSchedule
    {
        $machine = $this->machine($tag, 100, 'idle', 16.0);
        $mold = $this->mold($tag . '-M', $this->product);
        $mold->compatibleMachines()->sync([$machine->id]);
        $wo = $this->workOrder($tag . '-1', WorkOrderStatus::Planned, '100', 5);
        $wo->forceFill(['planned_start' => $this->tomorrow->copy()->setTime(8, 0)])->save();
        $row = $this->rawSchedule($wo, $machine, $mold, 8, 12, 'pending');
        $this->planner->confirm([$row->id], $this->user->id);
        app(\App\Modules\Production\Services\WorkOrderService::class)->start($wo->fresh(), $this->user->id);
        return $row->fresh();
    }

    private function makeProduct(string $tag): Product
    {
        return Product::create([
            'part_number' => 'M50-' . $tag . '-' . substr(uniqid(), -6),
            'name' => 'M050 Probe Product ' . $tag,
            'unit_of_measure' => 'pcs',
            'standard_cost' => '10.00',
            'is_active' => true,
        ]);
    }

    private function machine(string $code, int $tonnage, string $status, float $hours): Machine
    {
        return Machine::factory()->create([
            'machine_code' => substr($code, 0, 20),
            'tonnage' => $tonnage,
            'status' => $status,
            'available_hours_per_day' => $hours,
        ]);
    }

    private function mold(string $code, Product $product): Mold
    {
        return Mold::create([
            'mold_code' => substr($code, 0, 20),
            'name' => 'M050 Probe Mold ' . $code,
            'product_id' => $product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 10000000,
            'status' => 'available',
        ]);
    }

    private function workOrder(string $number, WorkOrderStatus $status, string $quantity, int $priority): WorkOrder
    {
        return WorkOrder::factory()->create([
            'wo_number' => substr($number, 0, 20),
            'product_id' => $this->product->id,
            'quantity_target' => $quantity,
            'planned_start' => $this->tomorrow->copy()->setTime(8, 0),
            'planned_end' => $this->tomorrow->copy()->setTime(17, 0),
            'priority' => $priority,
            'status' => $status->value,
            'created_by' => $this->user->id,
            // M050 probe WOs carry no BOM; mark them non-stock so the
            // service's material-plan gate at start() passes without setup.
            'work_order_class' => 'non_stock',
            'exception_reason' => 'M050 capacity probe',
            'exception_authorized_by' => $this->user->id,
        ]);
    }

    private function rawSchedule(
        WorkOrder $wo,
        Machine $machine,
        Mold $mold,
        int $startHour,
        int $endHour,
        string $status,
    ): ProductionSchedule {
        return ProductionSchedule::create([
            'work_order_id' => $wo->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => $this->tomorrow->copy()->setTime($startHour, 0),
            'scheduled_end' => $this->tomorrow->copy()->setTime($endHour, 0),
            'priority_order' => 1,
            'status' => $status,
            'is_confirmed' => $status === 'confirmed',
        ]);
    }
}
