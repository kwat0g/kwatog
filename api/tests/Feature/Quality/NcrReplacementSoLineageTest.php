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
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NcrReplacementSoLineageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('quality.ncr.replacement_work_order_priority', 7);
    }

    private function closeForOutput(NcrDisposition $disposition, bool $soBound): array
    {
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
        $product = Product::factory()->create();
        $order = $soBound ? SalesOrder::factory()->create() : null;
        $line = $order ? SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 10,
        ]) : null;
        $original = WorkOrder::factory()->create([
            'product_id' => $product->id,
            'sales_order_id' => $order?->id,
            'sales_order_item_id' => $line?->id,
            'quantity_target' => 10,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $original->id,
            'recorded_by' => $user->id,
            'recorded_at' => now(),
            'good_count' => 10,
            'reject_count' => 0,
        ]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-T-'.substr(uniqid(), -8),
            'stage' => 'outgoing',
            'status' => 'failed',
            'product_id' => $product->id,
            'entity_type' => 'work_order',
            'entity_id' => $original->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => 10,
            'sample_size' => 5,
        ]);
        $ncr = NonConformanceReport::factory()->create([
            'inspection_id' => $inspection->id,
            'product_id' => $product->id,
            'affected_quantity' => 10,
        ]);
        $ncr->forceFill(['disposition' => $disposition])->save();
        foreach ([NcrActionType::Corrective, NcrActionType::Preventive] as $type) {
            NcrAction::create([
                'ncr_id' => $ncr->id,
                'action_type' => $type->value,
                'description' => 'Resolve failed batch',
                'performed_by' => $user->id,
                'performed_at' => now(),
            ]);
        }

        $closed = app(NcrService::class)->close($ncr, $user);
        $replacementId = $disposition === NcrDisposition::Scrap
            ? $closed->replacement_work_order_id : $closed->rework_work_order_id;

        return [WorkOrder::findOrFail($replacementId), $order, $line, $ncr, $original];
    }

    public function test_scrap_replacement_carries_output_sales_order_lineage(): void
    {
        [$replacement, $order, $line, $ncr] = $this->closeForOutput(NcrDisposition::Scrap, true);

        $this->assertSame($order->id, $replacement->sales_order_id);
        $this->assertSame($line->id, $replacement->sales_order_item_id);
        $this->assertSame($ncr->id, $replacement->parent_ncr_id);
    }

    public function test_rework_replacement_carries_output_sales_order_lineage(): void
    {
        [$replacement, $order, $line, $ncr] = $this->closeForOutput(NcrDisposition::Rework, true);

        $this->assertSame($order->id, $replacement->sales_order_id);
        $this->assertSame($line->id, $replacement->sales_order_item_id);
        $this->assertSame($ncr->id, $replacement->parent_ncr_id);
    }

    public function test_unbound_output_remains_unbound(): void
    {
        [$replacement] = $this->closeForOutput(NcrDisposition::Scrap, false);

        $this->assertNull($replacement->sales_order_id);
        $this->assertNull($replacement->sales_order_item_id);
    }

    public function test_customer_complaint_without_inspection_does_not_spawn_production(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
        $ncr = NonConformanceReport::factory()->create([
            'source' => 'customer_complaint',
            'product_id' => Product::factory()->create()->id,
            'inspection_id' => null,
        ]);
        $ncr->forceFill(['disposition' => NcrDisposition::Scrap])->save();
        foreach ([NcrActionType::Corrective, NcrActionType::Preventive] as $type) {
            NcrAction::create([
                'ncr_id' => $ncr->id,
                'action_type' => $type->value,
                'description' => 'Resolve complaint',
                'performed_by' => $user->id,
                'performed_at' => now(),
            ]);
        }

        $closed = app(NcrService::class)->close($ncr, $user);

        $this->assertNull($closed->replacement_work_order_id);
        $this->assertSame(0, WorkOrder::count());
    }

    public function test_mrp_rerun_counts_planned_and_confirmed_so_bound_replacement_without_another_wo(): void
    {
        Event::fake([MrpPlanGenerated::class]);
        [$replacement, $order, $line, , $original] = $this->closeForOutput(NcrDisposition::Scrap, true);
        $order->forceFill(['status' => 'confirmed'])->save();
        $original->forceFill([
            'status' => WorkOrderStatus::Completed,
            'quantity_produced' => 10,
            'quantity_good' => 10,
        ])->save();

        $material = Item::factory()->create();
        $bom = Bom::create(['product_id' => $original->product_id, 'version' => 1, 'is_active' => true]);
        BomItem::create([
            'bom_id' => $bom->id,
            'item_id' => $material->id,
            'quantity_per_unit' => '1.0000',
            'unit' => $material->unit_of_measure,
            'waste_factor' => '0.00',
            'sort_order' => 0,
        ]);
        StockLevel::create([
            'item_id' => $material->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity' => '10.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '5.0000',
            'lock_version' => 0,
        ]);

        $engine = app(MrpEngineService::class);
        $engine->runForSalesOrder($order->fresh());
        $engine->runForSalesOrder($order->fresh());

        $this->assertSame(2, WorkOrder::where('sales_order_item_id', $line->id)->count());
        $this->assertSame(1, WorkOrder::where('sales_order_item_id', $line->id)
            ->where('status', WorkOrderStatus::Planned->value)->count());

        $replacement->forceFill(['status' => WorkOrderStatus::Confirmed])->save();
        $engine->runForSalesOrder($order->fresh());
        $engine->runForSalesOrder($order->fresh());

        $this->assertSame(2, WorkOrder::where('sales_order_item_id', $line->id)->count());
        $this->assertSame(0, WorkOrder::where('sales_order_item_id', $line->id)
            ->where('status', WorkOrderStatus::Planned->value)->count());
    }
}
