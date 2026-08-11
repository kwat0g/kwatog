<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ChainListenerRun;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Inventory\Enums\IncomingQcHandoffStatus;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Listeners\TriggerIncomingQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GrnIncomingQcHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_incoming_qc_failure_keeps_grn_pending_and_records_manual_handoff(): void
    {
        $user = User::factory()->create();
        $failing = \Mockery::mock(InspectionService::class);
        $failing->shouldReceive('createIncomingForItem')
            ->zeroOrMoreTimes()
            ->andThrow(new BusinessRuleException('Inspection sequence is not configured.'));
        $this->app->instance(InspectionService::class, $failing);

        $grn = $this->createGrn($user);

        $this->assertSame(GrnStatus::PendingQc, $grn->status);
        $this->assertSame(IncomingQcHandoffStatus::ManualRequired, $grn->fresh()->incoming_qc_handoff_status);
        $this->assertNull($grn->fresh()->qc_inspection_id);
        $this->assertSame(0, Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)->count());
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => GoodsReceiptNoteCreated::class,
        ]);
        $this->assertDatabaseHas('chain_listener_runs', [
            'event_type' => GoodsReceiptNoteCreated::class,
            'listener_class' => TriggerIncomingQC::class,
            'outcome_status' => ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
            'outcome_code' => 'incoming_qc_manual_required',
        ]);
    }

    public function test_retry_stages_one_inspection_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $failing = \Mockery::mock(InspectionService::class);
        $failing->shouldReceive('createIncomingForItem')
            ->zeroOrMoreTimes()
            ->andThrow(new BusinessRuleException('Quality setup is temporarily unavailable.'));
        $this->app->instance(InspectionService::class, $failing);

        $grn = $this->createGrn($user);
        $this->app->forgetInstance(InspectionService::class);

        $retried = app(GrnService::class)->retryIncomingQcHandoff($grn->fresh());
        $this->assertSame(IncomingQcHandoffStatus::Generated, $retried->incoming_qc_handoff_status);
        $this->assertNotNull($retried->qc_inspection_id);

        app(TriggerIncomingQC::class)->handle(new GoodsReceiptNoteCreated($retried->fresh()));

        $this->assertSame(1, Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)->count());
        $this->assertSame(
            $retried->qc_inspection_id,
            $grn->fresh()->qc_inspection_id,
        );
    }

    public function test_hashed_retry_route_requires_quality_manage_permission(): void
    {
        $receiver = User::factory()->create();
        $failing = \Mockery::mock(InspectionService::class);
        $failing->shouldReceive('createIncomingForItem')
            ->zeroOrMoreTimes()
            ->andThrow(new BusinessRuleException('Quality setup is temporarily unavailable.'));
        $this->app->instance(InspectionService::class, $failing);
        $grn = $this->createGrn($receiver);
        $this->app->forgetInstance(InspectionService::class);

        $role = Role::query()->create([
            'name' => 'Incoming QC Recovery',
            'slug' => 'incoming-qc-recovery-'.bin2hex(random_bytes(3)),
            'is_system' => false,
        ]);
        $permission = Permission::query()->create([
            'name' => 'Manage inspections',
            'slug' => 'quality.inspections.manage',
            'module' => 'quality',
        ]);
        $role->permissions()->attach($permission);
        $operator = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/inventory/grn/'.$grn->hash_id.'/retry-incoming-qc')
            ->assertOk()
            ->assertJsonPath('data.incoming_qc_handoff.status', IncomingQcHandoffStatus::Generated->value);
    }

    private function createGrn(User $user): GoodsReceiptNote
    {
        $item = Item::factory()->create(['is_active' => true]);
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Incoming QC handoff material',
            'quantity' => '25.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '250.00',
            'quantity_received' => '0.000',
        ]);
        $location = WarehouseLocation::factory()->create();

        return app(GrnService::class)->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '10.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $user);
    }
}
