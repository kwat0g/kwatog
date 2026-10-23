<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ReceivesProductionOutput;
use Tests\TestCase;

/** Customer ordering, Sales/MRP planning, and role-backed Warehouse/Production/Quality custody. */
class WarehouseChainIntegrityTest extends TestCase
{
    use ReceivesProductionOutput;
    use RefreshDatabase;

    public function test_material_custody_output_quality_and_unused_return_preserve_source_stock(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class, UomSeeder::class]);
        $this->travelTo(now()->startOfMinute());
        $sales = User::factory()->withRole('sales_officer')->create();
        $ppc = User::factory()->withRole('ppc_officer')->create();
        $warehouse = User::factory()->withRole('warehouse_staff')->create();
        $production = User::factory()->withRole('production_manager')->create();
        $quality = User::factory()->withRole('qc_inspector')->create();
        User::factory()->withRole('system_admin')->create();
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);
        $product = Product::factory()->create(['standard_cost' => '5.0000', 'unit_of_measure' => 'pcs']);
        app(InspectionSpecService::class)->upsertForProduct($product->id, [
            ['parameter_name' => 'Surface condition', 'parameter_type' => 'visual',
                'acceptance_criterion' => 'No cracks, contamination, or visible surface damage.', 'is_critical' => false],
        ], $quality->id);
        $material = Item::factory()->create(['unit_of_measure' => 'kg', 'standard_cost' => '10.0000', 'reorder_point' => 0]);
        $bin = WarehouseLocation::factory()->create();
        foreach (['LOT-A', 'LOT-B'] as $lot) {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::AdjustmentIn, itemId: $material->id, toLocationId: $bin->id,
                quantity: '5.000', unitCost: '10.0000', lotNumber: $lot, createdBy: $warehouse->id,
            ));
        }
        $bom = Bom::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
        BomItem::create(['bom_id' => $bom->id, 'item_id' => $material->id,
            'quantity_per_unit' => '1.0000', 'unit' => 'kg', 'waste_factor' => '0', 'sort_order' => 0]);
        $customer = Customer::factory()->create();
        $portal = CustomerPortalUser::create(['customer_id' => $customer->id, 'name' => 'Warehouse journey customer',
            'email' => 'warehouse-journey@customer.test', 'password' => bcrypt('password'), 'is_active' => true]);
        PriceAgreement::create(['customer_id' => $customer->id, 'product_id' => $product->id,
            'price' => '100.00', 'pricing_method' => 'flat', 'effective_from' => now()->subDay(),
            'effective_to' => now()->addMonth()]);
        $order = $this->actingAs($portal, 'customer_portal')->postJson('/api/v1/b2b/customer/orders', [
            'items' => [['product_id' => $product->hash_id, 'quantity' => 4,
                'delivery_date' => now()->addDays(7)->toDateString()]],
        ], ['Idempotency-Key' => 'warehouse-customer-journey'])->assertCreated();
        $so = SalesOrder::where('so_number', $order->json('data.so_number'))->sole();
        Auth::shouldUse('web');
        $this->actingAs($sales, 'web')->postJson('/api/v1/crm/sales-orders/'.$so->hash_id.'/confirm')->assertOk();
        $this->assertSame('confirmed', $so->fresh()->status->value);
        $this->assertTrue(MrpPlan::where('sales_order_id', $so->id)->exists());
        $wo = WorkOrder::where('sales_order_id', $so->id)->sole();
        $this->assertSame(4, (int) $wo->quantity_target);
        $this->assertSame('4.000', (string) $wo->materials()->sole()->bom_quantity);
        // PPC supplies an executable slot after MRP generates the actual work order.
        $wo->update(['planned_start' => now(), 'planned_end' => now()->addHours(4)]);
        ProductionSchedule::where('work_order_id', $wo->id)->delete();
        $machine = Machine::factory()->create(['status' => 'idle']);
        $mold = Mold::create([
            'mold_code' => 'CHAIN-MOLD', 'name' => 'Chain fixture mold', 'product_id' => $product->id,
            'cavity_count' => 1, 'cycle_time_seconds' => 25, 'output_rate_per_hour' => 120,
            'setup_time_minutes' => 10, 'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000, 'status' => 'available',
        ]);
        $mold->compatibleMachines()->sync([$machine->id]);
        $crew = $this->assignProductionCrew($wo, $production);
        // Pin the in-process sample to 2 so the startup trial settles exactly
        // the 2 good pieces this walk records; the remaining 2 pieces' worth
        // of material is what the unused return below preserves.
        app(SettingsService::class)->set('quality.in_process.sample_size', 2);
        $this->actingAs($ppc);
        app(WorkOrderService::class)->confirm($wo, $machine->id, $mold->id);
        $this->assertSame('4.000', (string) StockLevel::query()->where('item_id', $material->id)->sole()->reserved_quantity);
        $reservation = MaterialReservation::query()->where('work_order_id', $wo->id)->where('status', 'reserved')->firstOrFail();
        $response = $this->actingAs($warehouse)->postJson('/api/v1/inventory/material-issues', [
            'work_order_id' => $wo->hash_id, 'issued_date' => now()->toDateString(),
            'items' => [['item_id' => $material->hash_id, 'location_id' => $bin->hash_id,
                'quantity_issued' => '4.000', 'material_reservation_id' => $reservation->hash_id]],
        ])->assertCreated();
        $slip = MaterialIssueSlip::query()->where('slip_number', $response->json('data.slip_number'))->sole();
        $source = $slip->items()->firstOrFail()->stockMovement;
        $recorder = $crew->recorder_employee_id;
        $recipient = Employee::findOrFail($recorder);
        $before = StockMovement::query()->count();
        $this->postJson('/api/v1/inventory/material-handovers/group', [
            'received_by_employee_id' => $recipient->hash_id,
            'sources' => [['movement_id' => $source->hash_id, 'expected_quantity' => '4.000']],
        ])->assertOk();
        $this->assertSame($before, StockMovement::query()->count());
        $this->finishedGoodsItemFor($product);
        $this->finishedGoodsLocation();
        $startResponse = $this->actingAs($production)->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/start', [
            'expected_crew_id' => $crew->hash_id,
        ]);
        $this->assertTrue($startResponse->isSuccessful(), $startResponse->getContent());
        $process = Inspection::query()->where('entity_type', 'work_order')->where('entity_id', $wo->id)
            ->where('stage', InspectionStage::InProcess->value)->firstOrFail();
        // Startup-trial gate: the pinned in-process sample may only receive
        // QC evidence after Production records the trial pieces.
        $trialResponse = $this->actingAs($production)
            ->withHeaders(['X-Idempotency-Key' => 'warehouse-chain-trial'])
            ->postJson('/api/v1/production/work-orders/'.$wo->hash_id.'/trial-outputs', [
                'crew_id' => $crew->hash_id,
                'trial_quantity' => min((int) $process->sample_size, (int) $wo->quantity_target),
                'recording_reason' => 'Startup trial pieces for the pinned in-process QC sample.',
            ]);
        $this->assertTrue($trialResponse->isSuccessful(), $trialResponse->getContent());
        // withHeaders() sticks to later requests on the same test case; without
        // this flush the trial key collides with the plain output recording.
        $this->flushHeaders();
        $this->submitInspectionPassThroughApi($process, $quality);
        // QC acceptance settles the startup trial: the 2 pinned sample pieces
        // ARE this order's good output, receipt included — nothing left to
        // record through the plain output endpoint.
        $this->assertSame(2, (int) $wo->fresh()->quantity_good);
        $output = $wo->outputs()->sole();
        $this->assertNotNull($output->production_receipt_movement_id);
        $returnPayload = ['quantity_returned' => '2.000', 'expected_returned_quantity' => '0.000',
            'reason' => 'Actual unused material received by Warehouse'];
        $url = '/api/v1/inventory/stock-movements/'.$source->hash_id.'/return-unused';
        $this->actingAs($warehouse)->withHeader('Idempotency-Key', 'warehouse-chain-return')
            ->postJson($url, $returnPayload)->assertSuccessful()->assertJsonPath('data.lot_number', $source->lot_number)
            ->assertJsonPath('data.total_cost', '20.00');
        $this->postJson($url, $returnPayload)->assertSuccessful();
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'material_return')->count());
        $this->assertSame('8.000', (string) StockLevel::query()->where('item_id', $material->id)->sole()->quantity);
        $this->getJson('/api/v1/inventory/stock-movements?work_order_id='.$wo->hash_id)->assertOk()->assertJsonCount(3, 'data');
        $this->withHeader('Idempotency-Key', 'warehouse-chain-floor')->postJson($url, [
            ...$returnPayload, 'quantity_returned' => '0.001', 'expected_returned_quantity' => '2.000',
        ])->assertUnprocessable();
        $this->assertSame('8.000', (string) StockLevel::query()->where('item_id', $material->id)->sole()->quantity);
    }
}
