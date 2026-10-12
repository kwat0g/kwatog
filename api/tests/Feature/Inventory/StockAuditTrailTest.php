<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StockAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_movement_and_balance_changes_write_actor_audit_rows(): void
    {
        Event::fake([StockMovementCompleted::class]);
        $actor = User::factory()->create();
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $this->actingAs($actor);

        $movement = app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt,
            itemId: $item->id,
            quantity: '3.000',
            toLocationId: $location->id,
            unitCost: '12.00',
            createdBy: $actor->id,
        ));
        $level = StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();

        $movementAudit = AuditLog::query()
            ->where('model_type', (new StockMovement)->getMorphClass())
            ->where('model_id', $movement->id)
            ->where('action', 'created')
            ->firstOrFail();
        $levelAudit = AuditLog::query()
            ->where('model_type', (new StockLevel)->getMorphClass())
            ->where('model_id', $level->id)
            ->where('action', 'updated')
            ->firstOrFail();

        $this->assertSame($actor->id, $movementAudit->user_id);
        $this->assertSame('3.000', (string) $movementAudit->new_values['quantity']);
        $this->assertSame($actor->id, $levelAudit->user_id);
        $this->assertSame('0.000', (string) $levelAudit->old_values['quantity']);
        $this->assertSame('3.000', (string) $levelAudit->new_values['quantity']);
    }
}
