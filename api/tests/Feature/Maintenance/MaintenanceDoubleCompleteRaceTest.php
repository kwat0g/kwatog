<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Models\Mold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Maintenance work-order complete race (P01-01 + P03-01 shape).
 *
 * `complete()` guards on the passed model's status outside the transaction and
 * updates the mold lifecycle from stale reads (`maintenance_count + 1`,
 * `total_maintenance_cost + $cost`). Two concurrent completes both pass the
 * guard and both bump the counters → maintenance_count is inflated and history
 * is duplicated. The work-order row (and the mold row) must be locked and
 * re-read inside the transaction.
 */
class MaintenanceDoubleCompleteRaceTest extends TestCase
{
    use RefreshDatabase;

    private MaintenanceWorkOrderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(MaintenanceWorkOrderService::class);
    }

    private function mold(): Mold
    {
        return Mold::create([
            'mold_code'                => 'M-' . substr(uniqid(), -6),
            'name'                     => 'Test mold',
            'product_id'               => Product::factory()->create()->id,
            'cavity_count'             => 1,
            'cycle_time_seconds'       => 10,
            'output_rate_per_hour'     => 300,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots'       => 1000000,
            'current_shot_count'       => 5000,
            'status'                   => 'in_use',
        ]);
    }

    private function inProgressWorkOrder(Mold $mold, User $by): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::create([
            'mwo_number'        => 'MWO-' . substr(uniqid(), -8),
            'maintainable_type' => 'mold',
            'maintainable_id'   => $mold->id,
            'type'              => 'preventive',
            'description'       => 'Shot-count PM',
            'status'            => 'in_progress',
            'created_by'        => $by->id,
        ]);
    }

    public function test_second_concurrent_complete_is_blocked_and_counters_bump_once(): void
    {
        $mold = $this->mold();
        $by   = User::factory()->create(['is_active' => true]);
        $wo   = $this->inProgressWorkOrder($mold, $by);

        // Two technicians each hold a stale in-progress instance.
        $techA = MaintenanceWorkOrder::query()->findOrFail($wo->id);
        $techB = MaintenanceWorkOrder::query()->findOrFail($wo->id);

        $this->svc->complete($techA, [], $by);
        $this->assertSame(1, (int) Mold::query()->findOrFail($mold->id)->maintenance_count);

        // The second technician acts on the stale in_progress instance — must
        // be blocked, not double-completed.
        $this->expectException(ValidationException::class);

        $this->svc->complete($techB, [], $by);
    }

    public function test_single_complete_records_one_history_row(): void
    {
        $mold = $this->mold();
        $by   = User::factory()->create(['is_active' => true]);
        $wo   = $this->inProgressWorkOrder($mold, $by);

        $this->svc->complete($wo, ['remarks' => 'done'], $by);

        $this->assertSame(1, (int) Mold::query()->findOrFail($mold->id)->maintenance_count);
        $this->assertSame(
            1,
            \App\Modules\MRP\Models\MoldHistory::query()->where('mold_id', $mold->id)->count()
        );
    }
}
