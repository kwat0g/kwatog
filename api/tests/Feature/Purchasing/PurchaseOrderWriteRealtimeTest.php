<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Events\ChainStepAdvanced;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseOrderWriteRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    private function buyer(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
        ]);
    }

    private function chainPings(string $entityType, int $entityId): int
    {
        return DB::table('chain_step_runs')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('event_type', ChainStepAdvanced::class)
            ->count();
    }

    private function chainActions(string $entityType, int $entityId): array
    {
        $actions = DB::table('chain_step_runs')
            ->join('event_outbox', 'event_outbox.id', '=', 'chain_step_runs.outbox_id')
            ->where('chain_step_runs.entity_type', $entityType)
            ->where('chain_step_runs.entity_id', $entityId)
            ->where('chain_step_runs.event_type', ChainStepAdvanced::class)
            ->pluck('event_outbox.payload')
            ->map(fn (string $payload): string => json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['action'])
            ->all();
        sort($actions);

        return $actions;
    }

    public function test_partial_conversion_signals_the_new_po_and_changed_pr(): void
    {
        $buyer = $this->buyer();
        $pr = PurchaseRequest::factory()->create([
            'requested_by' => $buyer->id,
            'department_id' => null,
        ]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        $itemA = Item::factory()->create();
        $itemB = Item::factory()->create();
        $vendor = Vendor::factory()->create();
        $lineA = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $itemA->id,
            'suggested_vendor_id' => $vendor->id,
            'description' => 'Realtime partial line A',
            'quantity' => '2',
            'unit' => 'pcs',
            'estimated_unit_price' => '100.00',
        ]);
        PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $itemB->id,
            'suggested_vendor_id' => $vendor->id,
            'description' => 'Realtime partial line B',
            'quantity' => '2',
            'unit' => 'pcs',
            'estimated_unit_price' => '50.00',
        ]);

        $created = app(PurchaseOrderService::class)->convertFromPr(
            $pr->fresh(),
            [$lineA->id => $vendor->id],
            $buyer,
        );

        $this->assertCount(1, $created);
        $this->assertSame(1, $this->chainPings('purchase_order', $created[0]->id));
        $this->assertSame(['created'], $this->chainActions('purchase_order', $created[0]->id));
        $this->assertSame(1, $this->chainPings('purchase_request', $pr->id));
        $payload = DB::table('chain_step_runs')
            ->join('event_outbox', 'event_outbox.id', '=', 'chain_step_runs.outbox_id')
            ->where('chain_step_runs.entity_type', 'purchase_request')
            ->where('chain_step_runs.entity_id', $pr->id)
            ->where('chain_step_runs.event_type', ChainStepAdvanced::class)
            ->value('event_outbox.payload');
        $this->assertSame('updated', json_decode((string) $payload, true, flags: JSON_THROW_ON_ERROR)['action']);
    }

    public function test_po_update_delete_and_restore_each_stage_one_refresh(): void
    {
        $buyer = $this->buyer();
        $po = PurchaseOrder::factory()->create([
            'created_by' => $buyer->id,
            'status' => PurchaseOrderStatus::Draft->value,
        ]);
        $service = app(PurchaseOrderService::class);

        $service->update($po, ['remarks' => 'Updated draft'], $buyer);
        $this->assertSame(1, $this->chainPings('purchase_order', $po->id));
        $this->assertSame(['updated'], $this->chainActions('purchase_order', $po->id));

        $service->delete($po->fresh(), $buyer);
        $this->assertSame(2, $this->chainPings('purchase_order', $po->id));
        $this->assertSame(['deleted', 'updated'], $this->chainActions('purchase_order', $po->id));

        $service->restore(PurchaseOrder::withTrashed()->findOrFail($po->id), $buyer);
        $this->assertSame(3, $this->chainPings('purchase_order', $po->id));
        $this->assertSame(['deleted', 'restored', 'updated'], $this->chainActions('purchase_order', $po->id));
    }

    public function test_restoring_last_deleted_draft_po_reconciles_and_refreshes_source_pr(): void
    {
        $buyer = $this->buyer();
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create();
        $pr = PurchaseRequest::factory()->create([
            'requested_by' => $buyer->id,
            'department_id' => null,
        ]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Converted->value])->save();
        $prLine = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'suggested_vendor_id' => $vendor->id,
            'description' => 'Restored PO conversion line',
            'quantity' => '10',
            'unit' => 'pcs',
            'estimated_unit_price' => '100.00',
        ]);
        $po = PurchaseOrder::factory()->create([
            'created_by' => $buyer->id,
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr->id,
            'status' => PurchaseOrderStatus::Draft->value,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'purchase_request_item_id' => $prLine->id,
            'item_id' => $item->id,
            'description' => 'Restored PO conversion line',
            'quantity' => '10.00',
            'unit' => 'pcs',
            'unit_price' => '100.00',
            'total' => '1000.00',
        ]);
        $service = app(PurchaseOrderService::class);

        $service->delete($po, $buyer);
        $this->assertSame(PurchaseRequestStatus::Approved, $pr->fresh()->status);
        $this->assertSame(1, $this->chainPings('purchase_request', $pr->id));

        $service->restore(PurchaseOrder::withTrashed()->findOrFail($po->id), $buyer);

        $this->assertSame(PurchaseRequestStatus::Converted, $pr->fresh()->status);
        $this->assertSame(2, $this->chainPings('purchase_request', $pr->id));
        $this->assertSame(2, $this->chainPings('purchase_order', $po->id));
    }
}
