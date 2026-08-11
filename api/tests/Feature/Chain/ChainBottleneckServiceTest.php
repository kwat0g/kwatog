<?php

declare(strict_types=1);

namespace Tests\Feature\Chain;

use App\Common\Models\ChainListenerRun;
use App\Common\Services\ChainBottleneckService;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\ComplaintNcrHandoffStatus;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\IncomingQcHandoffStatus;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Enums\ReturnInspectionHandoffStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Series C — Task C5. Bottleneck detection feature test.
 *
 * Seeds rows directly via DB::table to keep the test independent of
 * domain service wiring (those are exercised by their own tests).
 */
class ChainBottleneckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_so_at_mrp_planned_detects_only_old_confirmed_records(): void
    {
        $this->bootstrap();
        // Two SOs at confirmed: one stuck > 48h, one fresh.
        $this->insertSalesOrder(101, 'SO-202604-0001', 'confirmed', Carbon::now()->subHours(72));
        $this->insertSalesOrder(102, 'SO-202604-0002', 'confirmed', Carbon::now()->subHours(2));
        $this->insertSalesOrder(103, 'SO-202604-0003', 'draft', Carbon::now()->subHours(72));

        $svc = app(ChainBottleneckService::class);
        $rows = $svc->detect('so_at_mrp_planned');

        $this->assertCount(1, $rows);
        $this->assertSame('SO-202604-0001', $rows[0]['doc_number']);
        $this->assertSame('sales_order', $rows[0]['entity_type']);
        $this->assertSame('confirmed', $rows[0]['status']);
        $this->assertGreaterThanOrEqual(48, (int) $rows[0]['hours_stuck']);
    }

    public function test_unknown_detector_key_returns_empty(): void
    {
        $svc = app(ChainBottleneckService::class);
        $this->assertSame([], $svc->detect('does_not_exist'));
    }

    public function test_detect_all_returns_keys_for_every_configured_bottleneck(): void
    {
        $svc = app(ChainBottleneckService::class);
        $all = $svc->detectAll();

        $expected = array_keys(config('chain.bottlenecks'));
        foreach ($expected as $k) {
            $this->assertArrayHasKey($k, $all);
        }
    }

    public function test_invoice_draft_overdue_finds_stuck_drafts(): void
    {
        $this->bootstrap();
        $this->insertInvoice(201, 'INV-202604-0001', 'draft', Carbon::now()->subHours(48));
        $this->insertInvoice(202, 'INV-202604-0002', 'draft', Carbon::now()->subHours(1));
        $this->insertInvoice(203, 'INV-202604-0003', 'finalized', Carbon::now()->subHours(48));

        $svc = app(ChainBottleneckService::class);
        $rows = $svc->detect('invoice_draft_overdue');

        $this->assertCount(1, $rows);
        $this->assertSame('INV-202604-0001', $rows[0]['doc_number']);
    }

    public function test_accepted_grn_without_active_bill_is_a_recovery_bottleneck(): void
    {
        $stale = GoodsReceiptNote::factory()->create([
            'status' => GrnStatus::Accepted,
            'accepted_at' => Carbon::now()->subHours(8),
            'updated_at' => Carbon::now()->subHours(8),
        ]);
        $fresh = GoodsReceiptNote::factory()->create([
            'status' => GrnStatus::Accepted,
            'accepted_at' => Carbon::now()->subHour(),
            'updated_at' => Carbon::now()->subHour(),
        ]);

        $rows = app(ChainBottleneckService::class)->detect('grn_accepted_without_bill');

        $this->assertCount(1, $rows);
        $this->assertSame($stale->grn_number, $rows[0]['doc_number']);
        $this->assertSame('grn', $rows[0]['entity_type']);
        $this->assertNotSame($fresh->grn_number, $rows[0]['doc_number']);

        Bill::factory()->create([
            'vendor_id' => $stale->vendor_id,
            'purchase_order_id' => $stale->purchase_order_id,
            'goods_receipt_note_id' => $stale->id,
            'status' => BillStatus::Draft,
        ]);

        $this->assertSame([], app(ChainBottleneckService::class)->detect('grn_accepted_without_bill'));
    }

    public function test_blocked_three_way_bill_is_visible_until_overridden_or_posted(): void
    {
        $blocked = Bill::factory()->create([
            'status' => BillStatus::Draft,
            'has_variances' => true,
            'three_way_match_snapshot' => ['overall_status' => 'blocked'],
            'three_way_overridden' => false,
            'updated_at' => Carbon::now()->subHours(8),
        ]);
        Bill::factory()->create([
            'status' => BillStatus::Draft,
            'has_variances' => true,
            'three_way_match_snapshot' => ['overall_status' => 'within_tolerance'],
            'three_way_overridden' => false,
            'updated_at' => Carbon::now()->subHours(8),
        ]);

        $rows = app(ChainBottleneckService::class)->detect('bill_three_way_manual_review');

        $this->assertCount(1, $rows);
        $this->assertSame($blocked->bill_number, $rows[0]['doc_number']);
        $this->assertSame('bill', $rows[0]['entity_type']);

        $blocked->update(['three_way_overridden' => true]);
        $this->assertSame([], app(ChainBottleneckService::class)->detect('bill_three_way_manual_review'));
    }

    public function test_confirmed_delivery_without_invoice_is_a_recovery_bottleneck(): void
    {
        $this->bootstrap();
        $this->insertSalesOrder(401, 'SO-202604-0401', 'delivered', Carbon::now()->subHours(8));

        $stale = Carbon::now()->subHours(8);
        DB::table('deliveries')->insert([
            'id' => 401,
            'delivery_number' => 'DEL-202604-0401',
            'sales_order_id' => 401,
            'status' => 'confirmed',
            'scheduled_date' => Carbon::now()->subDays(9)->toDateString(),
            'confirmed_at' => $stale,
            'created_by' => 1,
            'created_at' => $stale,
            // An unrelated edit after confirmation must not reset the
            // invoice-handoff SLA clock.
            'updated_at' => Carbon::now(),
        ]);

        $fresh = $stale->copy()->addHours(7);
        DB::table('deliveries')->insert([
            'id' => 402,
            'delivery_number' => 'DEL-202604-0402',
            'sales_order_id' => 401,
            'status' => 'confirmed',
            'scheduled_date' => $fresh->toDateString(),
            'confirmed_at' => $fresh,
            'created_by' => 1,
            'created_at' => $fresh,
            'updated_at' => $fresh,
        ]);

        $rows = app(ChainBottleneckService::class)->detect('delivery_confirmed_without_invoice');

        $this->assertCount(1, $rows);
        $this->assertSame('DEL-202604-0401', $rows[0]['doc_number']);
        $this->assertSame('delivery', $rows[0]['entity_type']);
    }

    public function test_stale_good_output_without_receipt_is_a_work_order_bottleneck(): void
    {
        $staleAt = Carbon::now()->subHours(8);
        $staleWo = WorkOrder::factory()->create([
            'wo_number' => 'WO-OUTPUT-STALE',
            'updated_at' => Carbon::now(),
        ]);
        WorkOrderOutput::create([
            'work_order_id' => $staleWo->id,
            'recorded_by' => $staleWo->created_by,
            'recorded_at' => $staleAt,
            'good_count' => 10,
            'reject_count' => 0,
            'batch_code' => 'WO-OUTPUT-STALE-B01',
            'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::ManualRequired->value,
            'production_receipt_handoff_message' => 'Fix inventory setup.',
            'production_receipt_handoff_at' => $staleAt,
        ]);

        $freshWo = WorkOrder::factory()->create([
            'wo_number' => 'WO-OUTPUT-FRESH',
        ]);
        WorkOrderOutput::create([
            'work_order_id' => $freshWo->id,
            'recorded_by' => $freshWo->created_by,
            'recorded_at' => Carbon::now()->subHour(),
            'good_count' => 4,
            'reject_count' => 0,
            'batch_code' => 'WO-OUTPUT-FRESH-B01',
            'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::ManualRequired->value,
            'production_receipt_handoff_message' => 'Fix inventory setup.',
            'production_receipt_handoff_at' => Carbon::now()->subHour(),
        ]);

        $rows = app(ChainBottleneckService::class)->detect('production_output_without_receipt');

        $this->assertCount(1, $rows);
        $this->assertSame('WO-OUTPUT-STALE', $rows[0]['doc_number']);
        $this->assertSame('work_order', $rows[0]['entity_type']);
        $this->assertSame($staleWo->hash_id, $rows[0]['entity_id']);
        $this->assertGreaterThanOrEqual(4, (int) $rows[0]['hours_stuck']);
    }

    public function test_stale_value_changing_movement_without_gl_is_a_finance_bottleneck(): void
    {
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $staleAt = Carbon::now()->subHours(8);

        $stale = StockMovement::create([
            'item_id' => $item->id,
            'to_location_id' => $location->id,
            'movement_type' => 'adjustment_in',
            'quantity' => '4.000',
            'unit_cost' => '5.0000',
            'total_cost' => '20.00',
            'reference_type' => 'manual_adjustment',
            'created_at' => $staleAt,
            'gl_handoff_status' => MovementGlHandoffStatus::ManualRequired->value,
            'gl_handoff_message' => 'Fix Accounting setup.',
            'gl_handoff_at' => $staleAt,
        ]);
        StockMovement::create([
            'item_id' => $item->id,
            'to_location_id' => $location->id,
            'movement_type' => 'adjustment_in',
            'quantity' => '4.000',
            'unit_cost' => '5.0000',
            'total_cost' => '20.00',
            'reference_type' => 'manual_adjustment',
            'created_at' => Carbon::now()->subHour(),
            'gl_handoff_status' => MovementGlHandoffStatus::ManualRequired->value,
            'gl_handoff_message' => 'Still fresh.',
            'gl_handoff_at' => Carbon::now()->subHour(),
        ]);

        $rows = app(ChainBottleneckService::class)->detect('inventory_movement_without_gl');

        $this->assertCount(1, $rows);
        $this->assertSame('stock_movement', $rows[0]['entity_type']);
        $this->assertSame($stale->hash_id, $rows[0]['entity_id']);
        $this->assertSame('MOV-'.$stale->id.' · manual_adjustment', $rows[0]['doc_number']);
        $this->assertSame('manual_required', $rows[0]['status']);
        $this->assertSame('finance_officer', $rows[0]['audience']);
        $this->assertGreaterThanOrEqual(4, (int) $rows[0]['hours_stuck']);
    }

    public function test_stale_return_without_quality_inspection_is_a_qc_bottleneck(): void
    {
        $staleAt = Carbon::now()->subHours(8);
        $product = Product::factory()->create(['part_number' => 'RMA-BOTTLENECK']);
        $rma = ReturnRequest::create([
            'rma_number' => 'RMA-QC-BLOCKED',
            'type' => 'customer_return',
            'status' => 'received',
            'inspection_handoff_status' => ReturnInspectionHandoffStatus::ManualRequired->value,
            'inspection_handoff_message' => 'Product has no active Quality spec.',
            'inspection_handoff_at' => $staleAt,
        ]);
        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'returned_quantity' => 3,
            'unit_price' => '10.00',
            'total' => '30.00',
        ]);

        $rows = app(ChainBottleneckService::class)->detect('return_without_inspection');

        $this->assertCount(1, $rows);
        $this->assertSame('return_request', $rows[0]['entity_type']);
        $this->assertSame($rma->hash_id, $rows[0]['entity_id']);
        $this->assertSame('RMA-QC-BLOCKED', $rows[0]['doc_number']);
        $this->assertSame('qc_inspector', $rows[0]['audience']);
        $this->assertGreaterThanOrEqual(4, (int) $rows[0]['hours_stuck']);
    }

    public function test_stale_complaint_without_ncr_is_a_qc_bottleneck(): void
    {
        $staleAt = Carbon::now()->subHours(8);
        $customer = Customer::factory()->create();
        $creator = User::factory()->create();
        $complaint = CustomerComplaint::create([
            'complaint_number' => 'CMP-QC-BLOCKED',
            'customer_id' => $customer->id,
            'received_date' => $staleAt->toDateString(),
            'severity' => 'medium',
            'status' => 'open',
            'description' => 'Complaint has no linked NCR.',
            'affected_quantity' => 2,
            'created_by' => $creator->id,
            'ncr_handoff_status' => ComplaintNcrHandoffStatus::ManualRequired->value,
            'ncr_handoff_message' => 'Quality setup is incomplete.',
            'ncr_handoff_at' => $staleAt,
        ]);

        $rows = app(ChainBottleneckService::class)->detect('complaint_without_ncr');

        $this->assertCount(1, $rows);
        $this->assertSame('customer_complaint', $rows[0]['entity_type']);
        $this->assertSame($complaint->hash_id, $rows[0]['entity_id']);
        $this->assertSame('CMP-QC-BLOCKED', $rows[0]['doc_number']);
        $this->assertSame('qc_inspector', $rows[0]['audience']);
        $this->assertGreaterThanOrEqual(4, (int) $rows[0]['hours_stuck']);
    }

    public function test_stale_grn_without_incoming_qc_is_a_qc_bottleneck(): void
    {
        $staleAt = Carbon::now()->subHours(8);
        $item = Item::factory()->create(['is_active' => true]);
        $grn = GoodsReceiptNote::factory()->create([
            'status' => GrnStatus::PendingQc,
            'incoming_qc_handoff_status' => IncomingQcHandoffStatus::ManualRequired->value,
            'incoming_qc_handoff_message' => 'Incoming QC trigger failed.',
            'incoming_qc_handoff_at' => $staleAt,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $grn->purchase_order_id,
            'item_id' => $item->id,
            'description' => 'Bottleneck material',
            'quantity' => '10.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '100.00',
            'quantity_received' => '10.000',
        ]);
        $location = WarehouseLocation::factory()->create();
        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '10.000',
            'quantity_accepted' => '0.000',
            'unit_cost' => '10.00',
        ]);

        $rows = app(ChainBottleneckService::class)->detect('grn_without_incoming_qc');

        $this->assertCount(1, $rows);
        $this->assertSame('grn', $rows[0]['entity_type']);
        $this->assertSame($grn->hash_id, $rows[0]['entity_id']);
        $this->assertSame($grn->grn_number, $rows[0]['doc_number']);
        $this->assertSame('qc_inspector', $rows[0]['audience']);
        $this->assertGreaterThanOrEqual(4, (int) $rows[0]['hours_stuck']);
    }

    public function test_automation_summary_surfaces_publication_and_listener_failures(): void
    {
        $failedAt = Carbon::now()->subHours(3);
        DB::table('event_outbox')->insert([
            'id' => '00000000-0000-0000-0000-000000000301',
            'event_type' => 'p2p.purchase_order.approved',
            'payload' => json_encode(['purchaseOrder' => ['__type' => 'scalar', 'value' => null]], JSON_THROW_ON_ERROR),
            'dedupe_key' => 'test-automation-summary-301',
            'status' => 'failed',
            'attempts' => 3,
            'available_at' => $failedAt,
            'updated_at' => $failedAt,
            'created_at' => $failedAt,
        ]);
        DB::table('event_outbox')->insert([
            'id' => '00000000-0000-0000-0000-000000000309',
            'event_type' => 'p2p.purchase_request.approved',
            'payload' => json_encode(['purchaseRequest' => ['__type' => 'scalar', 'value' => null]], JSON_THROW_ON_ERROR),
            'dedupe_key' => 'test-automation-summary-309',
            'status' => 'pending',
            'attempts' => 1,
            'available_at' => $failedAt,
            'updated_at' => $failedAt,
            'created_at' => $failedAt,
        ]);
        DB::table('event_outbox')->insert([
            'id' => '00000000-0000-0000-0000-000000000310',
            'event_type' => 'p2p.purchase_request.approved',
            'payload' => json_encode(['purchaseRequest' => ['__type' => 'scalar', 'value' => null]], JSON_THROW_ON_ERROR),
            'dedupe_key' => 'test-automation-summary-310',
            'status' => 'processing',
            'attempts' => 1,
            'available_at' => $failedAt,
            'locked_at' => null,
            'updated_at' => $failedAt,
            'created_at' => $failedAt,
        ]);
        DB::table('chain_listener_runs')->insert([
            'id' => '00000000-0000-0000-0000-000000000302',
            'outbox_id' => '00000000-0000-0000-0000-000000000301',
            'job_uuid' => '00000000-0000-0000-0000-000000000303',
            'event_type' => 'p2p.purchase_order.approved',
            'listener_class' => 'App\\Modules\\Purchasing\\Listeners\\NotifyOnPurchaseOrderApproved',
            'listener_method' => 'handle',
            'status' => 'failed',
            'attempts' => 3,
            'failed_at' => $failedAt,
            'last_attempt_at' => $failedAt,
            'outcome_status' => ChainListenerRun::OUTCOME_FAILED,
            'outcome_code' => 'queue_failed',
            'outcome_message' => 'permanent listener failure',
            'outcome_at' => $failedAt,
            'created_at' => $failedAt,
            'updated_at' => $failedAt,
        ]);
        DB::table('event_outbox')->insert([
            'id' => '00000000-0000-0000-0000-000000000304',
            'event_type' => 'p2p.purchase_request.approved',
            'payload' => json_encode(['purchaseRequest' => ['__type' => 'scalar', 'value' => null]], JSON_THROW_ON_ERROR),
            'dedupe_key' => 'test-automation-summary-304',
            'status' => 'published',
            'attempts' => 1,
            'available_at' => $failedAt,
            'published_at' => $failedAt,
            'updated_at' => $failedAt,
            'created_at' => $failedAt,
        ]);
        DB::table('chain_listener_runs')->insert([
            'id' => '00000000-0000-0000-0000-000000000305',
            'outbox_id' => '00000000-0000-0000-0000-000000000304',
            'job_uuid' => '00000000-0000-0000-0000-000000000306',
            'event_type' => 'p2p.purchase_request.approved',
            'listener_class' => 'App\\Modules\\Purchasing\\Listeners\\ConsolidatePurchaseOrders',
            'listener_method' => 'handle',
            'status' => ChainListenerRun::STATUS_COMPLETED,
            'attempts' => 1,
            'completed_at' => $failedAt,
            'last_attempt_at' => $failedAt,
            'outcome_status' => ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
            'outcome_code' => 'purchase_request_manual_conversion_required',
            'outcome_message' => 'Manual conversion required.',
            'outcome_at' => $failedAt,
            'created_at' => $failedAt,
            'updated_at' => $failedAt,
        ]);
        DB::table('chain_listener_runs')->insert([
            'id' => '00000000-0000-0000-0000-000000000307',
            'outbox_id' => '00000000-0000-0000-0000-000000000301',
            'job_uuid' => '00000000-0000-0000-0000-000000000308',
            'event_type' => 'p2p.purchase_order.approved',
            'listener_class' => 'App\\Modules\\Purchasing\\Listeners\\PrepareSupplierDispatch',
            'listener_method' => 'handle',
            'status' => ChainListenerRun::STATUS_PROCESSING,
            'attempts' => 1,
            'last_attempt_at' => null,
            'created_at' => $failedAt,
            'updated_at' => $failedAt,
        ]);

        $summary = app(ChainBottleneckService::class)->automationSummary();

        $this->assertSame('attention', $summary['status']);
        $this->assertTrue($summary['outbox']['available']);
        $this->assertSame(1, $summary['outbox']['failed']);
        $this->assertSame(1, $summary['outbox']['stale_pending']);
        $this->assertSame(1, $summary['outbox']['stale_processing']);
        $this->assertSame(1, $summary['listeners']['failed']);
        $this->assertSame(1, $summary['listeners']['stale_processing']);
        $this->assertSame(1, $summary['listeners']['outcomes']['failed']);
        $this->assertSame(1, $summary['listeners']['outcomes']['manual_required']);
        $this->assertSame(3, $summary['listeners']['outcomes']['total']);
        $this->assertSame(1, $summary['listeners']['outcomes']['unclassified']);
        $this->assertNotNull($summary['outbox']['oldest_failure_at']);
        $this->assertNotNull($summary['listeners']['oldest_failure_at']);
        $this->assertTrue($summary['failed_jobs']['available']);
        $this->assertSame(0, $summary['failed_jobs']['total']);
        $this->assertNull($summary['failed_jobs']['oldest_at']);
    }

    public function test_bottleneck_cron_alerts_when_the_durable_automation_pipeline_is_stuck(): void
    {
        $staleAt = Carbon::now()->subMinutes(20);
        DB::table('event_outbox')->insert([
            'id' => '00000000-0000-0000-0000-000000000311',
            'event_type' => 'p2p.purchase_request.approved',
            'payload' => json_encode(['purchaseRequest' => ['__type' => 'scalar', 'value' => null]], JSON_THROW_ON_ERROR),
            'dedupe_key' => 'test-automation-alert-311',
            'status' => 'pending',
            'attempts' => 1,
            'available_at' => $staleAt,
            'updated_at' => $staleAt,
            'created_at' => $staleAt,
        ]);

        $this->artisan('chain:check-bottlenecks')->assertSuccessful();
        $this->artisan('chain:check-bottlenecks')->assertSuccessful();

        $this->assertDatabaseHas('alerts', [
            'type' => 'chain_bottleneck',
            'title' => 'Cross-module automation needs attention',
            'entity_id' => null,
        ]);
        $this->assertSame(1, DB::table('alerts')
            ->where('type', 'chain_bottleneck')
            ->where('title', 'Cross-module automation needs attention')
            ->count());
    }

    public function test_failed_queue_jobs_make_automation_health_require_attention(): void
    {
        $failedAt = Carbon::now()->subMinutes(5);
        DB::table('failed_jobs')->insert([
            'uuid' => '00000000-0000-0000-0000-000000000399',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: queue delivery failed',
            'failed_at' => $failedAt,
        ]);

        $summary = app(ChainBottleneckService::class)->automationSummary();

        $this->assertSame('attention', $summary['status']);
        $this->assertTrue($summary['failed_jobs']['available']);
        $this->assertSame(1, $summary['failed_jobs']['total']);
        $this->assertNotNull($summary['failed_jobs']['oldest_at']);
    }

    /**
     * Regression: the hourly `chain:check-bottlenecks` cron must survive the
     * hash_id → bigint boundary.
     *
     * detectAll() emits `entity_id` as a hash_id (its other consumer is the SPA
     * widget), but `alerts.entity_id` is a bigint. The command passed the hash
     * straight into the query and died with
     * SQLSTATE[22P02] invalid input syntax for type bigint — every hour, on any
     * database that had even one stuck record.
     */
    public function test_bottleneck_cron_writes_decoded_entity_ids_to_alerts(): void
    {
        $this->bootstrap();
        $this->insertSalesOrder(301, 'SO-202604-0301', 'confirmed', Carbon::now()->subHours(72));

        $this->artisan('chain:check-bottlenecks')->assertSuccessful();

        $alert = DB::table('alerts')
            ->where('type', 'chain_bottleneck')
            ->where('entity_type', 'sales_order')
            ->first();

        $this->assertNotNull($alert, 'the cron should have raised an alert for the stuck SO');
        $this->assertSame(301, (int) $alert->entity_id);
    }

    /** Re-running within the dedup window must not duplicate the alert. */
    public function test_bottleneck_cron_is_idempotent_within_dedup_window(): void
    {
        $this->bootstrap();
        $this->insertSalesOrder(302, 'SO-202604-0302', 'confirmed', Carbon::now()->subHours(72));

        $this->artisan('chain:check-bottlenecks')->assertSuccessful();
        $this->artisan('chain:check-bottlenecks')->assertSuccessful();

        $count = DB::table('alerts')
            ->where('type', 'chain_bottleneck')
            ->where('entity_type', 'sales_order')
            ->where('entity_id', 302)
            ->count();

        $this->assertSame(1, $count);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Seed minimal role + user + customer so FK constraints pass.
     */
    private function bootstrap(): void
    {
        DB::table('roles')->insertOrIgnore([
            'id' => 1,
            'name' => 'Tester',
            'slug' => 'tester',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        DB::table('users')->insertOrIgnore([
            'id' => 1,
            'name' => 'Test Creator',
            'email' => 'creator@test.local',
            'password' => bcrypt('Password1!'),
            'role_id' => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        DB::table('customers')->insertOrIgnore([
            'id' => 1,
            'name' => 'Acme',
            'address' => 'Test Street, Cavite',
            'is_active' => true,
            'payment_terms_days' => 30,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function insertSalesOrder(int $id, string $number, string $status, Carbon $updatedAt): void
    {
        DB::table('sales_orders')->insert([
            'id' => $id,
            'so_number' => $number,
            'customer_id' => 1,
            'date' => Carbon::now()->toDateString(),
            'subtotal' => '0.00',
            'vat_amount' => '0.00',
            'total_amount' => '0.00',
            'status' => $status,
            'payment_terms_days' => 30,
            'created_by' => 1,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function insertInvoice(int $id, string $number, string $status, Carbon $updatedAt): void
    {
        DB::table('invoices')->insert([
            'id' => $id,
            'invoice_number' => $number,
            'customer_id' => 1,
            'date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'is_vatable' => true,
            'subtotal' => '0.00',
            'vat_amount' => '0.00',
            'total_amount' => '0.00',
            'amount_paid' => '0.00',
            'balance' => '0.00',
            'status' => $status,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }
}
