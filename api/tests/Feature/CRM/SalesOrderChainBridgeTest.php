<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 8 — SO → WO Chain Bridge integration tests.
 *
 * Validates the end-to-end flow: SO confirmation triggers MRP, creates WOs
 * and auto-PRs, and the chain result summary is correctly shaped. Also tests
 * graceful failure on missing BOM.
 */
class SalesOrderChainBridgeTest extends TestCase
{
    use RefreshDatabase;

    private SalesOrderService $soService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->soService = app(SalesOrderService::class);
    }

    // ─── Service-level tests ──────────────────────────────────────────────

    public function test_confirm_so_triggers_mrp_and_creates_work_orders(): void
    {
        [$so, $product, $item] = $this->makeSoWithBom();

        $confirmed = $this->soService->confirm($so);

        $this->assertSame(SalesOrderStatus::Confirmed->value, $confirmed->status->value);

        // MRP plan should exist.
        $plan = MrpPlan::where('sales_order_id', $so->id)->first();
        $this->assertNotNull($plan, 'MRP plan should be created on SO confirm.');
        $this->assertSame('active', $plan->status->value);

        // WOs should be created (one per SO line).
        $wos = WorkOrder::where('sales_order_id', $so->id)->get();
        $this->assertCount(1, $wos, 'One WO per SO line expected.');
        $this->assertSame((int) $product->id, (int) $wos->first()->product_id);
        $this->assertSame(100, (int) $wos->first()->quantity_target);
        $this->assertSame('planned', $wos->first()->status->value);
    }

    public function test_confirm_so_returns_chain_result_summary(): void
    {
        [$so] = $this->makeSoWithBom();

        $result = $this->soService->confirmWithChainResult($so);

        // Shape assertions.
        $this->assertArrayHasKey('so', $result);
        $this->assertArrayHasKey('chain_result', $result);

        $cr = $result['chain_result'];
        $this->assertSame($so->so_number, $cr['so_number']);
        $this->assertGreaterThanOrEqual(1, $cr['work_orders_created']);
        $this->assertIsInt($cr['auto_scheduled']);
        $this->assertIsInt($cr['needs_manual']);
        $this->assertIsInt($cr['shortages']);
        $this->assertIsInt($cr['prs_created']);
        $this->assertIsArray($cr['work_orders']);
        $this->assertIsArray($cr['scheduling_conflicts']);

        // Each WO summary has required keys.
        foreach ($cr['work_orders'] as $woSummary) {
            $this->assertArrayHasKey('id', $woSummary);
            $this->assertArrayHasKey('wo_number', $woSummary);
            $this->assertArrayHasKey('status', $woSummary);
            $this->assertArrayHasKey('quantity_target', $woSummary);
            $this->assertArrayHasKey('needs_manual_scheduling', $woSummary);
        }
    }

    public function test_confirm_so_creates_auto_prs_for_shortages(): void
    {
        // Create SO with BOM but NO stock — should generate shortages + a PR.
        [$so, , $rawItem] = $this->makeSoWithBom(stockQuantity: 0);

        $result = $this->soService->confirmWithChainResult($so);

        $cr = $result['chain_result'];
        $this->assertGreaterThanOrEqual(1, $cr['shortages'], 'Shortages expected when no stock.');
        $this->assertGreaterThanOrEqual(1, $cr['prs_created'], 'Auto-PR expected for shortage.');

        // Verify the PR exists.
        $plan = MrpPlan::where('sales_order_id', $so->id)->first();
        $pr = PurchaseRequest::where('mrp_plan_id', $plan->id)->first();
        $this->assertNotNull($pr, 'Purchase request should be created for shortages.');
        $this->assertTrue((bool) $pr->is_auto_generated, 'PR should be marked auto-generated.');
    }

    public function test_confirm_so_handles_missing_bom_gracefully(): void
    {
        // Create SO with a product that has NO BOM — MRP should still succeed
        // (with a warning diagnostic) and create a WO.
        [$so, $product] = $this->makeSoWithoutBom();

        $result = $this->soService->confirmWithChainResult($so);

        $cr = $result['chain_result'];
        $this->assertGreaterThanOrEqual(1, $cr['work_orders_created'],
            'WO should still be created even when BOM is missing.');
        $this->assertSame(0, $cr['shortages'],
            'No shortages expected when BOM is missing (no demand explosion).');

        // Verify the plan has a diagnostic warning.
        $plan = MrpPlan::where('sales_order_id', $so->id)->first();
        $diagnostics = $plan->diagnostics;
        $missingBomWarning = collect($diagnostics)->firstWhere('type', 'missing_bom');
        $this->assertNotNull($missingBomWarning,
            'Plan diagnostics should contain a missing_bom warning.');
        $this->assertSame('warning', $missingBomWarning['kind']);
    }

    // ─── HTTP-level tests ─────────────────────────────────────────────────

    public function test_confirm_endpoint_returns_chain_result_json(): void
    {
        [$so] = $this->makeSoWithBom();
        $user = $this->makeUserWithPermission('crm.sales_orders.confirm');

        $response = $this->actingAs($user)
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['id', 'so_number', 'status'],
            'chain_result' => [
                'so_number',
                'work_orders_created',
                'auto_scheduled',
                'needs_manual',
                'shortages',
                'prs_created',
                'work_orders',
                'scheduling_conflicts',
            ],
        ]);

        $this->assertSame('confirmed', $response->json('data.status'));
    }

    public function test_confirm_endpoint_rejects_non_draft_so(): void
    {
        $user = $this->makeUserWithPermission('crm.sales_orders.confirm');
        $so = $this->makeSo(SalesOrderStatus::Confirmed);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm");

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Only draft sales orders can be confirmed.']);
    }

    public function test_confirm_endpoint_rejects_empty_so(): void
    {
        $user = $this->makeUserWithPermission('crm.sales_orders.confirm');
        $so = $this->makeSo(SalesOrderStatus::Draft); // No line items

        $response = $this->actingAs($user)
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm");

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Cannot confirm a sales order with no items.']);
    }

    public function test_confirm_endpoint_forbidden_without_permission(): void
    {
        [$so] = $this->makeSoWithBom();
        $user = $this->makeUserWithPermission('crm.sales_orders.view'); // View only

        $response = $this->actingAs($user)
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm");

        $response->assertForbidden();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function makeUserWithPermission(string $permSlug): User
    {
        $role = Role::create([
            'name' => 'CB Test ' . substr(uniqid(), -5),
            'slug' => 'cb_test_' . substr(uniqid(), -8),
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => $permSlug],
            ['name' => ucfirst(str_replace('.', ' ', $permSlug)), 'module' => 'crm'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeSo(SalesOrderStatus $status): SalesOrder
    {
        $customer = Customer::create([
            'name'               => 'Cust ' . substr(uniqid(), -5),
            'is_active'          => true,
            'payment_terms_days' => 30,
        ]);

        $user = $this->makeUserWithPermission('crm.sales_orders.confirm');

        return SalesOrder::create([
            'so_number'    => 'SO-CB-' . substr(uniqid(), -8),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '500.00',
            'vat_amount'   => '60.00',
            'total_amount' => '560.00',
            'status'       => $status->value,
            'created_by'   => $user->id,
        ]);
    }

    /**
     * Create a draft SO with one line item, a product with an active BOM,
     * and (optionally) stock for the raw material.
     *
     * @return array{0: SalesOrder, 1: Product, 2: Item}
     */
    private function makeSoWithBom(float $stockQuantity = 1000): array
    {
        $customer = Customer::create([
            'name'               => 'Cust ' . substr(uniqid(), -5),
            'is_active'          => true,
            'payment_terms_days' => 30,
        ]);

        $user = $this->makeUserWithPermission('crm.sales_orders.confirm');

        $product = Product::create([
            'part_number'     => strtoupper('PT-' . substr(uniqid(), -7)),
            'name'            => 'Wiper Bushing ' . substr(uniqid(), -5),
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '50.00',
            'is_active'       => true,
        ]);

        // Raw material item.
        $category = ItemCategory::firstOrCreate(['name' => 'Raw Materials']);
        $rawItem = Item::create([
            'code'            => 'RM-' . substr(uniqid(), -7),
            'name'            => 'PP Resin ' . substr(uniqid(), -5),
            'category_id'     => $category->id,
            'item_type'       => 'raw_material',
            'unit_of_measure' => 'kg',
            'standard_cost'   => '85.0000',
            'lead_time_days'  => 7,
            'is_active'       => true,
        ]);

        // BOM: 1 product requires 0.5 kg of raw material.
        $bom = Bom::create([
            'product_id' => $product->id,
            'version'    => 1,
            'is_active'  => true,
        ]);
        BomItem::create([
            'bom_id'            => $bom->id,
            'item_id'           => $rawItem->id,
            'quantity_per_unit' => '0.5000',
            'unit'              => 'kg',
            'waste_factor'      => '5.00',
            'sort_order'        => 0,
        ]);

        // Stock level (warehouse → zone → location → stock).
        if ($stockQuantity > 0) {
            $warehouse = Warehouse::create([
                'name'      => 'Main WH',
                'code'      => 'WH-' . substr(uniqid(), -5),
                'is_active' => true,
            ]);
            $zone = WarehouseZone::create([
                'warehouse_id' => $warehouse->id,
                'name'         => 'Zone A',
                'code'         => 'ZA',
                'zone_type'    => 'raw_materials',
            ]);
            $location = WarehouseLocation::create([
                'zone_id'   => $zone->id,
                'code'      => 'A-01',
                'is_active' => true,
            ]);
            StockLevel::create([
                'item_id'           => $rawItem->id,
                'location_id'       => $location->id,
                'quantity'          => number_format($stockQuantity, 3, '.', ''),
                'reserved_quantity' => '0.000',
                'weighted_avg_cost' => '85.0000',
            ]);
        }

        // Draft SO with one line.
        $so = SalesOrder::create([
            'so_number'    => 'SO-CB-' . substr(uniqid(), -8),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '5000.00',
            'vat_amount'   => '600.00',
            'total_amount' => '5600.00',
            'status'       => SalesOrderStatus::Draft->value,
            'created_by'   => $user->id,
        ]);

        SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $product->id,
            'quantity'           => 100,
            'unit_price'         => '50.00',
            'total'              => '5000.00',
            'quantity_delivered' => 0,
            'delivery_date'      => now()->addDays(14)->toDateString(),
        ]);

        return [$so, $product, $rawItem];
    }

    /**
     * Create a draft SO with a product that has NO BOM.
     *
     * @return array{0: SalesOrder, 1: Product}
     */
    private function makeSoWithoutBom(): array
    {
        $customer = Customer::create([
            'name'               => 'Cust ' . substr(uniqid(), -5),
            'is_active'          => true,
            'payment_terms_days' => 30,
        ]);

        $user = $this->makeUserWithPermission('crm.sales_orders.confirm');

        $product = Product::create([
            'part_number'     => strtoupper('PT-' . substr(uniqid(), -7)),
            'name'            => 'No BOM Product ' . substr(uniqid(), -5),
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '100.00',
            'is_active'       => true,
        ]);

        $so = SalesOrder::create([
            'so_number'    => 'SO-CB-' . substr(uniqid(), -8),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '10000.00',
            'vat_amount'   => '1200.00',
            'total_amount' => '11200.00',
            'status'       => SalesOrderStatus::Draft->value,
            'created_by'   => $user->id,
        ]);

        SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $product->id,
            'quantity'           => 100,
            'unit_price'         => '100.00',
            'total'              => '10000.00',
            'quantity_delivered' => 0,
            'delivery_date'      => now()->addDays(14)->toDateString(),
        ]);

        return [$so, $product];
    }
}
