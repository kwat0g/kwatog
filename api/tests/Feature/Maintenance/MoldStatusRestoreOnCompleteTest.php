<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Services\MaintenanceWorkOrderService;
use App\Modules\MRP\Enums\MoldStatus;
use App\Modules\MRP\Models\Mold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MT-01 — a mold auto-flipped to `maintenance` at its shot limit must return
 * to the schedulable pool when its MWO completes. `complete()` reset the shot
 * count but never touched the status, so the capacity planner (which only
 * accepts `available`/`in_use` molds) lost the mold permanently.
 */
class MoldStatusRestoreOnCompleteTest extends TestCase
{
    use RefreshDatabase;

    private MaintenanceWorkOrderService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(MaintenanceWorkOrderService::class);
    }

    private function mold(MoldStatus $status, int $shotCount): Mold
    {
        return Mold::create([
            'mold_code'                    => 'M-' . substr(uniqid(), -6),
            'name'                         => 'Test mold',
            'product_id'                   => Product::factory()->create()->id,
            'cavity_count'                 => 1,
            'cycle_time_seconds'           => 10,
            'output_rate_per_hour'         => 300,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots'           => 1000000,
            'current_shot_count'           => $shotCount,
            'status'                       => $status->value,
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

    public function test_completing_mwo_restores_mold_at_shot_limit_to_available(): void
    {
        // The exact state MoldService::incrementShots() leaves at the limit.
        $mold = $this->mold(MoldStatus::Maintenance, 100000);
        $by   = User::factory()->create(['is_active' => true]);
        $wo   = $this->inProgressWorkOrder($mold, $by);

        $this->svc->complete($wo, [], $by);

        $fresh = Mold::query()->findOrFail($mold->id);
        $this->assertSame(MoldStatus::Available, $fresh->status);
        $this->assertSame(0, (int) $fresh->current_shot_count);
    }

    public function test_completing_mwo_does_not_restore_a_retired_mold(): void
    {
        $mold = $this->mold(MoldStatus::Retired, 100000);
        $by   = User::factory()->create(['is_active' => true]);
        $wo   = $this->inProgressWorkOrder($mold, $by);

        $this->svc->complete($wo, [], $by);

        $fresh = Mold::query()->findOrFail($mold->id);
        $this->assertSame(MoldStatus::Retired, $fresh->status);
        // The completion path still ran — shot reset and lifecycle counters.
        $this->assertSame(0, (int) $fresh->current_shot_count);
        $this->assertSame(1, (int) $fresh->maintenance_count);
    }
}
