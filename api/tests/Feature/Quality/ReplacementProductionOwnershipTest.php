<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\MRP\Jobs\RunAutomaticMrpJob;
use App\Modules\MRP\Listeners\QueueMrpOnOutgoingInspectionFailed;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * O2C audit 2026-09-25 — one owner for replacement production.
 *
 * A failed outgoing batch was replaced twice: MRP (which subtracts failed
 * output from the order line) planned the shortfall, then NCR close — often
 * days later, after the CAPA — created another WO. A 10-piece failed lot left
 * 20 pieces of replacement production open. MRP now owns SO-bound batches and
 * re-plans as soon as the result is final; NCR still replaces stock batches.
 */
class ReplacementProductionOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('quality.ncr.replacement_work_order_priority', 7);
        $this->user = User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
        $this->actingAs($this->user);
    }

    /** @return array{Product, ?SalesOrder, ?SalesOrderItem, WorkOrder, Inspection} */
    private function failedBatch(bool $soBound): array
    {
        $product = Product::factory()->create();
        $order = $soBound ? SalesOrder::factory()->create() : null;
        $order?->forceFill(['status' => 'confirmed'])->save();
        $line = $order ? SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 10,
        ]) : null;
        $original = WorkOrder::factory()->create([
            'product_id' => $product->id, 'sales_order_id' => $order?->id, 'sales_order_item_id' => $line?->id,
            'quantity_target' => 10,
        ]);
        $original->forceFill(['status' => WorkOrderStatus::Completed, 'quantity_produced' => 10, 'quantity_good' => 10])->save();
        $output = WorkOrderOutput::create([
            'work_order_id' => $original->id, 'recorded_by' => $this->user->id, 'recorded_at' => now(),
            'good_count' => 10, 'reject_count' => 0,
        ]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-T-'.substr(uniqid(), -8), 'stage' => 'outgoing', 'status' => 'failed',
            'product_id' => $product->id, 'entity_type' => 'work_order', 'entity_id' => $original->id,
            'work_order_output_id' => $output->id, 'batch_quantity' => 10, 'sample_size' => 5,
        ]);

        return [$product, $order, $line, $original, $inspection];
    }

    private function closeWithScrap(Inspection $inspection, int $productId): NonConformanceReport
    {
        $ncr = NonConformanceReport::factory()->create([
            'inspection_id' => $inspection->id, 'product_id' => $productId, 'affected_quantity' => 10,
        ]);
        $ncr->forceFill(['disposition' => NcrDisposition::Scrap])->save();
        foreach ([NcrActionType::Corrective, NcrActionType::Preventive] as $type) {
            NcrAction::create([
                'ncr_id' => $ncr->id, 'action_type' => $type->value, 'description' => 'Resolve failed batch',
                'performed_by' => $this->user->id, 'performed_at' => now(),
            ]);
        }

        return app(NcrService::class)->close($ncr, $this->user);
    }

    public function test_mrp_plans_the_shortfall_once_and_ncr_close_adds_no_second_work_order(): void
    {
        Event::fake([MrpPlanGenerated::class]);
        [$product, $order, $line, $original, $inspection] = $this->failedBatch(true);
        $material = Item::factory()->create();
        $bom = Bom::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
        BomItem::create([
            'bom_id' => $bom->id, 'item_id' => $material->id, 'quantity_per_unit' => '1.0000',
            'unit' => $material->unit_of_measure, 'waste_factor' => '0.00', 'sort_order' => 0,
        ]);
        StockLevel::create([
            'item_id' => $material->id, 'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity' => '100.000', 'reserved_quantity' => '0.000', 'weighted_avg_cost' => '5.0000', 'lock_version' => 0,
        ]);

        $engine = app(MrpEngineService::class);
        $engine->runForSalesOrder($order->fresh());
        WorkOrder::where('sales_order_item_id', $line->id)->whereKeyNot($original->id)->first()
            ?->forceFill(['status' => WorkOrderStatus::Confirmed])->save();

        $closed = $this->closeWithScrap($inspection, $product->id);
        $engine->runForSalesOrder($order->fresh());

        $this->assertNull($closed->replacement_work_order_id);
        $open = WorkOrder::where('sales_order_item_id', $line->id)->whereKeyNot($original->id)
            ->where('status', '!=', WorkOrderStatus::Cancelled->value);
        $this->assertSame(10, (int) $open->sum('quantity_target'), 'a 10-piece failed lot needs 10 pieces of replacement');
    }

    public function test_a_failed_sales_order_batch_replans_its_order_immediately(): void
    {
        Bus::fake([RunAutomaticMrpJob::class]);
        [, $order, , , $inspection] = $this->failedBatch(true);

        app(QueueMrpOnOutgoingInspectionFailed::class)->handle(new InspectionFailed($inspection));

        Bus::assertDispatched(RunAutomaticMrpJob::class, fn (RunAutomaticMrpJob $job): bool => $job->salesOrderIds === [$order->id]);
    }

    public function test_a_failed_stock_batch_does_not_replan_and_ncr_still_replaces_it(): void
    {
        Bus::fake([RunAutomaticMrpJob::class]);
        [$product, , , , $inspection] = $this->failedBatch(false);

        app(QueueMrpOnOutgoingInspectionFailed::class)->handle(new InspectionFailed($inspection));
        $closed = $this->closeWithScrap($inspection, $product->id);

        Bus::assertNotDispatched(RunAutomaticMrpJob::class);
        $this->assertNotNull($closed->replacement_work_order_id);
        $this->assertSame(10, (int) WorkOrder::findOrFail($closed->replacement_work_order_id)->quantity_target);
    }
}
