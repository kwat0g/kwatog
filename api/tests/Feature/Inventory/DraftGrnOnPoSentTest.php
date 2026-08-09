<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Services\SettingsService;
use App\Common\Services\SystemActorService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Events\PurchaseOrderSent;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-08-08 — Expected GRN staging.
 *
 * When a PO is marked sent (or acknowledged on the supplier portal), a GRN in
 * `draft` status is auto-created with the PO lines pre-filled at zero
 * quantities — a receipt *expectation*, not a receipt. The warehouse then
 * finalizes it: assign a bin + actual quantity per line, and the GRN becomes
 * pending_qc (incoming QC + stock-on-accept take over from there).
 */
class DraftGrnOnPoSentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\WorkflowSeeder::class);

        // Automation actor for the listener attribution.
        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);
    }

    private function makeSentPo(): PurchaseOrder
    {
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'purchasing_officer')->value('id'),
        ]);
        $po = PurchaseOrder::factory()->create([
            'created_by' => $user->id,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Sent->value])->save();
        $item = Item::factory()->create(['is_active' => true]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin batch',
            'quantity'          => '100.000',
            'unit'              => 'kg',
            'unit_price'        => '12.50',
            'total'             => '1250.00',
            'quantity_received' => '0.000',
        ]);
        return $po;
    }

    public function test_mark_as_sent_stages_a_draft_grn(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'purchasing_officer')->value('id'),
        ]);
        $po = PurchaseOrder::factory()->create(['created_by' => $user->id]);
        // Factory default is draft — keep it that way; submit() requires draft.
        $item = Item::factory()->create(['is_active' => true]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin batch',
            'quantity'          => '100.000',
            'unit'              => 'kg',
            'unit_price'        => '12.50',
            'total'             => '1250.00',
            'quantity_received' => '0.000',
        ]);

        $svc = app(PurchaseOrderService::class);
        $svc->submit($po->fresh());
        // Walk the PO approval chain (purchasing_officer → finance_officer → VP)
        // so the PO becomes approved, then mark it sent.
        $po = $po->fresh();
        foreach (['purchasing_officer', 'finance_officer', 'system_admin'] as $role) {
            if ($po->status !== PurchaseOrderStatus::PendingApproval) {
                break;
            }
            $approver = User::factory()->create([
                'role_id' => Role::where('slug', $role)->value('id'),
            ]);
            $po = $svc->approve($po->fresh(), $approver, "approve as {$role}");
        }
        $svc->markAsSent($po->fresh());

        $grn = GoodsReceiptNote::where('purchase_order_id', $po->id)->first();
        $this->assertNotNull($grn, 'markAsSent should stage a draft GRN.');
        $this->assertSame(GrnStatus::Draft, $grn->status);
        $this->assertNull($grn->received_date);
        $this->assertSame(1, $grn->items()->count());
        $this->assertSame('0.000', (string) $grn->items()->first()->quantity_received);
        // No stock touched, PO totals untouched.
        $this->assertSame('0.00', (string) $po->fresh()->items()->first()->quantity_received);
    }

    public function test_draft_grn_is_idempotent_for_a_repeated_send_event(): void
    {
        $po = $this->makeSentPo();

        event(new PurchaseOrderSent($po->fresh()));
        event(new PurchaseOrderSent($po->fresh()));

        $this->assertSame(1, GoodsReceiptNote::where('purchase_order_id', $po->id)->count());
    }

    public function test_finalize_draft_turns_it_pending_qc_and_triggers_incoming_qc(): void
    {
        $po = $this->makeSentPo();
        event(new PurchaseOrderSent($po->fresh()));
        $grn = GoodsReceiptNote::where('purchase_order_id', $po->id)->firstOrFail();
        $grnItem = $grn->items()->firstOrFail();
        $location = WarehouseLocation::factory()->create();

        $by = User::factory()->create();
        $result = app(GrnService::class)->finalizeDraft($grn, [[
            'purchase_order_item_id' => $grnItem->purchase_order_item_id,
            'location_id'            => $location->id,
            'quantity_received'      => '80.000',
        ]], $by);

        $this->assertSame(GrnStatus::PendingQc, $result->status);
        $this->assertNotNull($result->received_date);
        $this->assertSame('80.000', (string) $result->items()->first()->quantity_received);
        $this->assertSame($location->id, $result->items()->first()->location_id);
        // PO line received total advanced; PO marked partially received.
        // PO quantity_received is a 2-dp decimal (the GRN item qty is 3-dp).
        $this->assertSame('80.00', (string) $po->fresh()->items()->first()->quantity_received);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);
        // Incoming QC inspection was created (synchronous trigger).
        $this->assertSame(1, Inspection::where('entity_type', 'grn')
            ->where('entity_id', $result->id)->count());
    }

    public function test_finalize_respects_the_over_receipt_cap(): void
    {
        $po = $this->makeSentPo();
        event(new PurchaseOrderSent($po->fresh()));
        $grn = GoodsReceiptNote::where('purchase_order_id', $po->id)->firstOrFail();
        $grnItem = $grn->items()->firstOrFail();
        $location = WarehouseLocation::factory()->create();

        $by = User::factory()->create();
        try {
            app(GrnService::class)->finalizeDraft($grn, [[
                'purchase_order_item_id' => $grnItem->purchase_order_item_id,
                'location_id'            => $location->id,
                'quantity_received'      => '150.000', // > 100 ordered
            ]], $by);
            $this->fail('Over-receipt should be rejected.');
        } catch (\App\Common\Exceptions\BusinessRuleException $e) {
            $this->assertStringContainsString('only 100', $e->getMessage());
        }
        $this->assertSame(GrnStatus::Draft, $grn->fresh()->status);
    }
}
