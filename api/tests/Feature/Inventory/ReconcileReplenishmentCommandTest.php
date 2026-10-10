<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\AutoReplenishmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReconcileReplenishmentCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bounded_reconciliation_advances_oldest_blocked_items(): void
    {
        $older = Item::factory()->create();
        $newer = Item::factory()->create();
        $type = $older->getMorphClass();

        foreach ([[$older, Carbon::now()->subHour()], [$newer, Carbon::now()]] as [$item, $updatedAt]) {
            $alert = Alert::create([
                'type' => AlertType::ReplenishmentBlocked->value,
                'severity' => AlertSeverity::Warning->value,
                'title' => 'Blocked',
                'message' => 'Repair master data',
                'entity_type' => $type,
                'entity_id' => $item->id,
                'condition_key' => Alert::conditionKeyFor(AlertType::ReplenishmentBlocked, $type, $item->id),
            ]);
            $alert->forceFill(['created_at' => $updatedAt, 'updated_at' => $updatedAt])->save();
        }

        $service = $this->mock(AutoReplenishmentService::class);
        $service->shouldReceive('checkAndReplenish')->once()->with($older->id)->andReturn(null);

        $this->artisan('inventory:reconcile-replenishment', ['--limit' => 1])
            ->assertExitCode(0);
    }
}
