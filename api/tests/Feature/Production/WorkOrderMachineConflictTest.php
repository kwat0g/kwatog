<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * OGAMI-015 — machine double-booking guard in WorkOrderService::confirm().
 *
 * A machine already committed to another Confirmed/InProgress work order
 * cannot take a second one. When both WOs carry schedule rows, the conflict is
 * narrowed to overlapping time windows; otherwise any other active WO on the
 * same machine blocks the confirm.
 *
 * These WOs have NO BOM, so confirm() reserves no materials (empty loop) and we
 * avoid stock-level setup — keeping the test focused on the conflict gate.
 */
class WorkOrderMachineConflictTest extends TestCase
{
    use RefreshDatabase;

    private WorkOrderService $service;
    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Silence chain/broadcast events fired after a status change.
        Event::fake();

        $this->service = app(WorkOrderService::class);
        $this->user    = User::factory()->create();
        $this->product = Product::create([
            'part_number'     => 'WO-CONF-1',
            'name'            => 'Conflict Product',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => 10.00,
            'is_active'       => true,
        ]);
    }

    private function machine(): Machine
    {
        return Machine::factory()->create(['status' => 'idle']);
    }

    private function mold(): Mold
    {
        return Mold::create([
            'mold_code'                     => 'MD-' . substr(uniqid(), -5),
            'name'                          => 'Test Mold',
            'product_id'                    => $this->product->id,
            'cavity_count'                  => 1,
            'cycle_time_seconds'            => 30,
            'output_rate_per_hour'          => 100,
            'setup_time_minutes'            => 10,
            'current_shot_count'            => 0,
            'max_shots_before_maintenance'  => 100000,
            'lifetime_max_shots'            => 1000000,
            'status'                        => 'available',
        ]);
    }

    private function plannedWo(?Machine $machine = null, ?Mold $mold = null): WorkOrder
    {
        return WorkOrder::factory()->create([
            'product_id'    => $this->product->id,
            'machine_id'    => $machine?->id,
            'mold_id'       => $mold?->id,
            'status'        => WorkOrderStatus::Planned->value,
            'planned_start' => Carbon::today()->addDay()->toDateTimeString(),
            'planned_end'   => Carbon::today()->addDays(2)->toDateTimeString(),
            'created_by'    => $this->user->id,
        ]);
    }

    private function schedule(WorkOrder $wo, Machine $machine, Mold $mold, Carbon $start, Carbon $end): ProductionSchedule
    {
        return ProductionSchedule::create([
            'work_order_id'   => $wo->id,
            'machine_id'      => $machine->id,
            'mold_id'         => $mold->id,
            'scheduled_start' => $start,
            'scheduled_end'   => $end,
            'priority_order'  => 1,
            'status'          => 'pending',
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────

    /** Baseline: confirming onto a free machine succeeds. */
    public function test_confirm_succeeds_on_free_machine(): void
    {
        $machine = $this->machine();
        $mold    = $this->mold();
        $wo      = $this->plannedWo($machine, $mold);

        $confirmed = $this->service->confirm($wo);

        $this->assertSame(WorkOrderStatus::Confirmed, $confirmed->status);
    }

    /**
     * No schedule rows on either side → blanket conflict. A machine bound to a
     * Confirmed WO blocks confirming a second WO.
     */
    public function test_confirm_blocked_when_machine_has_active_wo_without_schedules(): void
    {
        $machine = $this->machine();
        $mold    = $this->mold();

        $existing = $this->plannedWo($machine, $mold);
        $this->service->confirm($existing); // now Confirmed on the machine

        $second = $this->plannedWo($machine, $this->mold());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already committed to active work order');

        $this->service->confirm($second);
    }

    /**
     * Overlapping schedule windows on the same machine → conflict.
     */
    public function test_confirm_blocked_on_overlapping_schedule_window(): void
    {
        $machine = $this->machine();

        $existing = $this->plannedWo($machine, $this->mold());
        $this->schedule(
            $existing, $machine, $this->mold(),
            Carbon::parse('2026-07-01 08:00'), Carbon::parse('2026-07-01 12:00'),
        );
        $this->service->confirm($existing);

        $second = $this->plannedWo($machine, $this->mold());
        // Overlaps 10:00–14:00 with the existing 08:00–12:00 window.
        $this->schedule(
            $second, $machine, $this->mold(),
            Carbon::parse('2026-07-01 10:00'), Carbon::parse('2026-07-01 14:00'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlapping schedule window');

        $this->service->confirm($second);
    }

    /**
     * Non-overlapping schedule windows on the same machine → allowed.
     */
    public function test_confirm_allowed_on_non_overlapping_schedule_window(): void
    {
        $machine = $this->machine();

        $existing = $this->plannedWo($machine, $this->mold());
        $this->schedule(
            $existing, $machine, $this->mold(),
            Carbon::parse('2026-07-01 08:00'), Carbon::parse('2026-07-01 12:00'),
        );
        $this->service->confirm($existing);

        $second = $this->plannedWo($machine, $this->mold());
        // Starts exactly when the first ends — no overlap (half-open interval).
        $this->schedule(
            $second, $machine, $this->mold(),
            Carbon::parse('2026-07-01 12:00'), Carbon::parse('2026-07-01 16:00'),
        );

        $confirmed = $this->service->confirm($second);

        $this->assertSame(WorkOrderStatus::Confirmed, $confirmed->status);
    }
}
