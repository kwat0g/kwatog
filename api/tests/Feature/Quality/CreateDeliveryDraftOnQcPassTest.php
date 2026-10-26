<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Listeners\CreateDeliveryDraftOnQcPass;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Support\HasPassedInProcessQc;

/**
 * L-7 — Auto-delivery draft must inherit unit_price from the SO item, not '0.00'.
 *
 * The listener fires when an outgoing inspection passes; the resulting
 * DeliveryItem flows into the C-1 auto-invoice path on customer confirm.
 * Hardcoded '0.00' produced zero-amount invoices. Fix: copy unit_price from
 * the parent SalesOrderItem.
 */
class CreateDeliveryDraftOnQcPassTest extends TestCase
{
    use RefreshDatabase;
    use HasPassedInProcessQc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_auto_draft_inherits_unit_price_from_sales_order_item(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');

        $listener = new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class));
        $listener->handle(new InspectionPassed($inspection));

        $delivery = Delivery::query()
            ->where('sales_order_id', $wo->sales_order_id)
            ->first();
        $this->assertNotNull($delivery, 'Listener must draft a delivery for the SO.');

        $item = DeliveryItem::query()->where('delivery_id', $delivery->id)->first();
        $this->assertNotNull($item, 'Listener must add a delivery item.');

        $this->assertSame(
            '75.00',
            (string) $item->unit_price,
            'unit_price must be copied from the SalesOrderItem, not hardcoded 0.00.',
        );
        $this->assertSame('10.000', (string) $item->quantity);
        $this->assertSame($inspection->id, $item->inspection_id);
        $this->assertSame('transit', $delivery->cost_recognition_mode->value);
        $this->assertEquals(10, $delivery->stockReservations()->sum('quantity'));
    }

    public function test_sorted_failed_output_drafts_only_checked_good_quantity_once(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $inspection->forceFill(['status' => 'failed', 'accepted_quantity' => 9])->save();
        $sorter = User::factory()->create();
        $approver = User::factory()->create();
        \App\Modules\Quality\Models\NonConformanceReport::create([
            'ncr_number' => 'NCR-SORT-DRAFT', 'source' => 'inspection_fail', 'severity' => 'high', 'status' => 'in_progress',
            'inspection_id' => $inspection->id, 'product_id' => $inspection->product_id,
            'affected_quantity' => 1, 'defect_description' => 'One rejected piece.', 'disposition' => 'scrap',
            'created_by' => $sorter->id,
        ])->forceFill(['sorting_approved_at' => now(), 'sorting_evidence_reference' => 'SORT-10',
            'sorting_inspected_quantity' => 10, 'sorting_approved_by' => $approver->id, 'sorting_performed_by' => $sorter->id,
            'mrb_accepted_quantity' => 9])->save();
        $listener = new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class));
        $event = new \App\Modules\Quality\Events\OutgoingQuantityReleased($inspection);
        $listener->handle($event);
        $listener->handle($event);
        $this->assertSame(1, Delivery::where('sales_order_id', $wo->sales_order_id)->count());
        $this->assertSame('9.000', DeliveryItem::where('inspection_id', $inspection->id)->firstOrFail()->quantity);
        $this->assertSame(InspectionStatus::Failed, $inspection->fresh()->status);
    }

    public function test_repaired_pass_recovers_unallocated_original_release_into_the_same_draft(): void
    {
        [$wo, $original] = $this->arrange(unitPrice: '75.00');
        $original->forceFill(['status' => 'failed', 'accepted_quantity' => 8])->save();
        $sorter = User::factory()->create();
        $approver = User::factory()->create();
        \App\Modules\Quality\Models\NonConformanceReport::create([
            'ncr_number' => 'NCR-REPAIRED-DRAFT', 'source' => 'inspection_fail', 'severity' => 'high', 'status' => 'closed',
            'inspection_id' => $original->id, 'product_id' => $original->product_id,
            'affected_quantity' => 2, 'defect_description' => 'Two repaired pieces.', 'disposition' => 'rework',
            'created_by' => $sorter->id,
        ])->forceFill(['sorting_approved_at' => now(), 'sorting_evidence_reference' => 'BOX-1',
            'sorting_inspected_quantity' => 10, 'sorting_approved_by' => $approver->id, 'sorting_performed_by' => $sorter->id,
            'mrb_accepted_quantity' => 8])->save();
        $output = WorkOrderOutput::create([
            'work_order_id' => $wo->id, 'recorded_by' => $wo->created_by, 'recorded_at' => now(),
            'good_count' => 2, 'reject_count' => 0, 'batch_code' => 'L7-REPAIR-002',
        ]);
        $this->receipt($output, $wo);
        $repaired = $original->replicate();
        $repaired->forceFill(['inspection_number' => 'QC-REPAIR-DRAFT', 'work_order_output_id' => $output->id,
            'batch_quantity' => 2, 'sample_size' => 2, 'accepted_quantity' => 2, 'status' => 'passed'])->save();
        $draft = Delivery::create([
            'delivery_number' => 'DR-REPAIR-DRAFT', 'sales_order_id' => $wo->sales_order_id,
            'status' => 'scheduled', 'scheduled_date' => now()->addDay()->toDateString(),
            'created_by' => $wo->created_by, 'notes' => "Auto-drafted from WO {$wo->wo_number} on outgoing QC pass.",
        ]);
        DeliveryItem::create(['delivery_id' => $draft->id, 'sales_order_item_id' => $wo->sales_order_item_id,
            'inspection_id' => $repaired->id, 'quantity' => 2, 'unit_price' => '75.00']);

        $listener = new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class));
        $listener->handle(new InspectionPassed($repaired));
        $listener->handle(new InspectionPassed($repaired));

        $this->assertSame(1, Delivery::where('sales_order_id', $wo->sales_order_id)->count());
        $this->assertSame(2, $draft->items()->count());
        $this->assertEquals(10, $draft->items()->sum('quantity'));
        $this->assertEquals(10, $draft->stockReservations()->sum('quantity'));
        $this->assertSame(2, $draft->stockReservations()->count());
        $this->assertSame('8.000', $draft->items()->where('inspection_id', $original->id)->firstOrFail()->quantity);
        $this->assertSame(InspectionStatus::Failed, $original->fresh()->status);
    }

    public function test_later_output_extends_reserved_draft_without_duplicating_existing_holds(): void
    {
        [$wo, $original] = $this->arrange(unitPrice: '75.00');
        $original->forceFill(['accepted_quantity' => 8])->save();
        $listener = new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class));
        $listener->handle(new InspectionPassed($original));
        $draft = Delivery::where('sales_order_id', $wo->sales_order_id)->firstOrFail();
        $hold = $draft->stockReservations()->firstOrFail();
        $output = WorkOrderOutput::create([
            'work_order_id' => $wo->id, 'recorded_by' => $wo->created_by, 'recorded_at' => now(),
            'good_count' => 2, 'reject_count' => 0, 'batch_code' => 'L7-LATER-002',
        ]);
        $this->receipt($output, $wo);
        $later = $original->replicate();
        $later->forceFill(['inspection_number' => 'QC-LATER-DRAFT', 'work_order_output_id' => $output->id,
            'batch_quantity' => 2, 'sample_size' => 2, 'accepted_quantity' => 2])->save();
        $listener->handle(new InspectionPassed($later));
        $listener->handle(new InspectionPassed($later));
        $this->assertSame(1, Delivery::where('sales_order_id', $wo->sales_order_id)->count());
        $this->assertEquals(10, $draft->stockReservations()->sum('quantity'));
        $this->assertSame(2, $draft->stockReservations()->count());
        $this->assertSame('8.000', $hold->fresh()->quantity);
    }

    public function test_assignment_recovers_stock_automatically_and_retries_without_duplicate_holds(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $draft = Delivery::create(['delivery_number' => 'DR-ASSIGN-RECOVERY', 'sales_order_id' => $wo->sales_order_id,
            'status' => 'scheduled', 'scheduled_date' => now()->addDay()->toDateString(), 'created_by' => $wo->created_by]);
        DeliveryItem::create(['delivery_id' => $draft->id, 'sales_order_item_id' => $wo->sales_order_item_id,
            'inspection_id' => $inspection->id, 'quantity' => 10, 'unit_price' => '75.00']);
        $driver = User::factory()->create(['role_id' => Role::where('slug', 'driver')->firstOrFail()->id, 'is_active' => true]);
        $vehicle = \App\Modules\SupplyChain\Models\Vehicle::create(['name' => 'Recovery truck', 'plate_number' => 'REC-001',
            'vehicle_type' => 'truck', 'status' => 'available']);
        $service = app(\App\Modules\SupplyChain\Services\DeliveryService::class);
        $payload = ['driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'reason' => 'Initial assignment.'];
        $actor = User::findOrFail($wo->created_by);
        $service->assign($draft, $payload, $actor);
        $service->assign($draft, $payload, $actor);
        $this->assertEquals(10, $draft->stockReservations()->sum('quantity'));
        $this->assertSame(1, $draft->stockReservations()->count());
        $this->assertSame($driver->id, $draft->fresh()->driver_id);
        $this->assertSame('legacy', $draft->fresh()->cost_recognition_mode->value);
    }

    public function test_pick_guidance_counts_all_own_lot_holds_in_a_shared_bin(): void
    {
        [$wo, $original] = $this->arrange(unitPrice: '75.00');
        $original->forceFill(['accepted_quantity' => 8])->save();
        $firstReceipt = $original->workOrderOutput->productionReceiptMovement;
        $firstReceipt->update(['quantity' => 8]);
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'recorded_by' => $wo->created_by,
            'recorded_at' => now(), 'good_count' => 2, 'reject_count' => 0, 'batch_code' => 'SHARED-REPAIR']);
        $receipt = $firstReceipt->replicate();
        $receipt->forceFill(['quantity' => 2, 'total_cost' => 100, 'lot_number' => $output->batch_code, 'reference_id' => $output->id])->save();
        $output->update(['production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated']);
        $repair = $original->replicate();
        $repair->forceFill(['inspection_number' => 'QC-SHARED-REPAIR', 'work_order_output_id' => $output->id,
            'batch_quantity' => 2, 'sample_size' => 2, 'accepted_quantity' => 2])->save();
        (new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class)))->handle(new InspectionPassed($repair));
        $draft = Delivery::where('sales_order_id', $wo->sales_order_id)->firstOrFail();
        $guidance = app(\App\Modules\SupplyChain\Services\DeliveryService::class)->show($draft)->preparation;
        $this->assertSame(['0.000', '0.000'], $guidance->pluck('shortage')->all());
        $this->assertSame([null, null], $guidance->pluck('message')->all());
    }

    public function test_missing_receipt_rolls_back_automatic_draft_and_lines(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $inspection->workOrderOutput->update(['production_receipt_movement_id' => null]);
        try {
            (new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class)))->handle(new InspectionPassed($inspection));
            $this->fail('Unbacked stock must not be reserved.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('traceable finished-goods receipt', $error->getMessage());
        }
        $this->assertSame(0, Delivery::count());
        $this->assertSame(0, DeliveryItem::count());
    }

    public function test_auto_draft_does_not_bypass_sales_order_remaining_quantity(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $soItem = SalesOrderItem::query()->whereKey($wo->sales_order_item_id)->firstOrFail();

        $existing = Delivery::create([
            'delivery_number' => 'DL-L7-'.substr(uniqid(), -8),
            'sales_order_id' => $wo->sales_order_id,
            'status' => 'scheduled',
            'scheduled_date' => now()->toDateString(),
            'created_by' => $wo->created_by,
        ]);
        DeliveryItem::create([
            'delivery_id' => $existing->id,
            'sales_order_item_id' => $soItem->id,
            'quantity' => '6',
            'unit_price' => '75.00',
        ]);

        // The QC event would request 10 more units. The listener must surface
        // the state error to the queue worker and leave no over-delivery draft.
        try {
            (new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class)))
                ->handle(new InspectionPassed($inspection));
            $this->fail('An over-delivery must fail the stateful chain step.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('exceeds', strtolower($e->getMessage()));
        }

        $this->assertSame(1, Delivery::query()->count());
        $this->assertSame(1, DeliveryItem::query()->count());
    }

    public function test_invalid_work_order_line_does_not_commit_an_empty_delivery(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $wo->update(['sales_order_item_id' => null]);

        try {
            (new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class)))
                ->handle(new InspectionPassed($inspection));
            $this->fail('A WO without an SO line must fail the stateful chain step.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('sales-order line', strtolower($e->getMessage()));
        }

        $this->assertSame(0, Delivery::query()->count());
        $this->assertSame(0, DeliveryItem::query()->count());
    }

    public function test_stale_pass_event_does_not_draft_delivery_for_non_passed_inspection(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $staleEvent = new InspectionPassed($inspection->fresh());
        $inspection->update(['status' => InspectionStatus::Failed->value]);

        (new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class)))
            ->handle($staleEvent);

        $this->assertSame(0, Delivery::query()
            ->where('sales_order_id', $wo->sales_order_id)
            ->count());
    }

    public function test_legacy_product_only_pass_does_not_draft_delivery(): void
    {
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $inspection->update(['work_order_output_id' => null, 'accepted_quantity' => 0]);

        (new CreateDeliveryDraftOnQcPass(app(DocumentSequenceService::class)))
            ->handle(new InspectionPassed($inspection->fresh()));

        $this->assertSame(0, Delivery::query()->where('sales_order_id', $wo->sales_order_id)->count());
    }

    public function test_delivery_draft_notice_uses_exact_delivery_and_effective_view_permission(): void
    {
        app(SettingsService::class)->set('modules.supply_chain', true, 'modules');
        [$wo, $inspection] = $this->arrange(unitPrice: '75.00');
        $warehouseRole = Role::query()->where('slug', 'warehouse_staff')->firstOrFail();
        $allowed = User::factory()->create(['role_id' => $warehouseRole->id, 'is_active' => true]);
        $revoked = User::factory()->create(['role_id' => $warehouseRole->id, 'is_active' => true]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revoked->id,
            'permission_id' => Permission::query()->where('slug', 'supply_chain.deliveries.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $wo->created_by,
            'reason' => 'Delivery visibility removed for QC notice test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CreateDeliveryDraftOnQcPass::class)->handle(new InspectionPassed($inspection));

        $delivery = Delivery::query()->where('sales_order_id', $wo->sales_order_id)->firstOrFail();
        $notice = $allowed->notifications()->where('type', 'chain.delivery_drafted')->firstOrFail();
        $this->assertSame("/supply-chain/deliveries/{$delivery->hash_id}", $notice->data['link_to']);
        $this->assertSame(Delivery::class, $notice->data['subject_type']);
        $this->assertSame((string) $delivery->id, $notice->data['subject_id']);
        $this->assertSame($delivery->hash_id, $notice->data['entity_id']);
        $this->assertSame(0, $revoked->notifications()->where('type', 'chain.delivery_drafted')->count());
        $this->actingAs($allowed)->getJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id)->assertOk();
        $this->actingAs($revoked)->getJson('/api/v1/supply-chain/deliveries/'.$delivery->hash_id)->assertForbidden();
    }

    private function receipt(WorkOrderOutput $output, WorkOrder $wo): void
    {
        $product = $wo->product;
        $item = \App\Modules\Inventory\Models\Item::query()->where('code', $product->part_number)->first();
        if (! $item) {
            $item = \App\Modules\Inventory\Models\Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good']);
        }
        $location = \App\Modules\Inventory\Models\WarehouseLocation::factory()->create([
            'zone_id' => \App\Modules\Inventory\Models\WarehouseZone::factory()->create(['zone_type' => 'finished_goods'])->id,
        ]);
        \App\Modules\Inventory\Models\StockLevel::factory()->create([
            'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => $output->good_count, 'weighted_avg_cost' => 50,
        ]);
        $receipt = \App\Modules\Inventory\Models\StockMovement::create([
            'item_id' => $item->id, 'to_location_id' => $location->id, 'movement_type' => 'production_receipt',
            'quantity' => $output->good_count, 'unit_cost' => 50, 'total_cost' => 50 * $output->good_count,
            'lot_number' => $output->batch_code, 'reference_type' => 'work_order_output', 'reference_id' => $output->id,
            'created_by' => $wo->created_by, 'gl_handoff_status' => 'generated',
        ]);
        $output->update(['production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a minimum-viable SO+SOItem+WO+Inspection chain so the listener
     * has everything it needs to draft a delivery.
     *
     * @return array{0: WorkOrder, 1: Inspection}
     */
    private function arrange(string $unitPrice): array
    {
        $role = Role::firstOrCreate(['slug' => 'l7_test'], ['name' => 'L7 Test']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $checker = User::factory()->create(['is_active' => true]);

        $customer = Customer::create([
            'name' => 'Cust '.uniqid(),
            'is_active' => true,
            'payment_terms_days' => 30,
        ]);

        $product = Product::create([
            'part_number' => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name' => 'Wiper Bushing '.uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost' => '50.00',
            'is_active' => true,
        ]);

        $so = SalesOrder::create([
            'so_number' => 'SO-L7-'.substr(uniqid(), -10),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '0.00',
            'vat_amount' => '0.00',
            'total_amount' => '0.00',
            'status' => 'in_production',
            'created_by' => $user->id,
        ]);

        $soItem = SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id' => $product->id,
            'quantity' => '10',
            'unit_price' => $unitPrice,
            'total' => bcmul('10', $unitPrice, 2),
            'quantity_delivered' => 0,
            'delivery_date' => now()->addDays(7)->toDateString(),
        ]);

        $wo = WorkOrder::create([
            'wo_number' => 'WO-L7-'.substr(uniqid(), -8),
            'product_id' => $product->id,
            'sales_order_id' => $so->id,
            'sales_order_item_id' => $soItem->id,
            'quantity_target' => 10,
            'quantity_produced' => 10,
            'quantity_good' => 10,
            'quantity_rejected' => 0,
            'planned_start' => now()->subDay(),
            'planned_end' => now(),
            'status' => 'completed',
            'created_by' => $user->id,
        ]);
        $this->passInProcessQc($wo);

        $output = WorkOrderOutput::create([
            'work_order_id' => $wo->id,
            'recorded_by' => $user->id,
            'recorded_at' => now(),
            'good_count' => 10,
            'reject_count' => 0,
            'batch_code' => 'L7-BATCH-001',
        ]);

        $this->receipt($output, $wo);

        $inspection = Inspection::create([
            'inspection_number' => 'QC-L7-'.substr(uniqid(), -8),
            'stage' => InspectionStage::Outgoing->value,
            'status' => InspectionStatus::Passed->value,
            'product_id' => $product->id,
            'entity_type' => InspectionEntityType::WorkOrder->value,
            'entity_id' => $wo->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => 10,
            'accepted_quantity' => 10,
            'sample_size' => 5,
            'accept_count' => 0,
            'reject_count' => 1,
            'inspector_id' => $user->id,
            'reviewed_by' => $checker->id,
            'reviewed_at' => now(),
            'defect_count' => 0,
            'completed_at' => now(),
        ]);

        return [$wo, $inspection];
    }
}
