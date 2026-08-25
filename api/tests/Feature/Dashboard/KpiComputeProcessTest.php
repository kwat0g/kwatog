<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Services\KpiSnapshotService;
use Database\Seeders\KpiDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KpiComputeProcessTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_monthly_kpi_failure_is_reported_as_a_failed_process(): void
    {
        KpiDefinition::query()->create([
            'code' => 'valid_no_data',
            'name' => 'Valid KPI with no observations',
            'module' => 'production',
            'unit' => 'count',
            'direction' => 'higher_is_better',
            'target_value' => 1,
            'calculation_method' => 'computeOee',
            'is_active' => true,
            'display_order' => 1,
        ]);
        KpiDefinition::query()->create([
            'code' => 'broken_calculator',
            'name' => 'Broken calculator',
            'module' => 'production',
            'unit' => 'count',
            'direction' => 'higher_is_better',
            'target_value' => 1,
            'calculation_method' => 'missingCalculator',
            'is_active' => true,
            'display_order' => 2,
        ]);

        $result = app(KpiSnapshotService::class)->computeAll(2026, 7);

        $this->assertSame(1, $result['no_data']);
        $this->assertSame(0, $result['computed']);
        $this->assertSame(['broken_calculator'], array_column($result['failed'], 'code'));
    }

    public function test_seeded_active_catalog_computes_without_schema_failures(): void
    {
        $this->seed(KpiDefinitionSeeder::class);

        $result = app(KpiSnapshotService::class)->computeAll(2026, 7);

        $this->assertSame([], $result['failed']);
        $this->assertSame(
            KpiDefinition::query()->where('is_active', true)->count(),
            $result['computed'] + $result['no_data'],
        );
    }

    public function test_ar_aging_uses_open_invoice_balance_and_period_end_population(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'KPI Customer',
            'payment_terms_days' => 30,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoices')->insert([
            [
                'invoice_number' => 'KPI-AR-OVERDUE', 'customer_id' => $customerId,
                'date' => '2026-05-01', 'due_date' => '2026-05-15',
                'status' => 'finalized', 'total_amount' => '100.00', 'balance' => '100.00',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'invoice_number' => 'KPI-AR-CURRENT', 'customer_id' => $customerId,
                'date' => '2026-07-01', 'due_date' => '2026-07-20',
                'status' => 'partial', 'total_amount' => '50.00', 'balance' => '50.00',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'invoice_number' => 'KPI-AR-DRAFT', 'customer_id' => $customerId,
                'date' => '2026-07-02', 'due_date' => '2026-05-01',
                'status' => 'draft', 'total_amount' => '500.00', 'balance' => '500.00',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $definition = KpiDefinition::query()->create([
            'code' => 'test_ar_aging', 'name' => 'Test AR Aging', 'module' => 'accounting',
            'unit' => 'percentage', 'direction' => 'lower_is_better', 'target_value' => 5,
            'calculation_method' => 'computeArAging60d', 'is_active' => true, 'display_order' => 1,
        ]);

        $snapshot = app(KpiSnapshotService::class)->computeKpi($definition, 2026, 7);

        $this->assertNotNull($snapshot);
        $this->assertSame('66.6700', (string) $snapshot->actual_value);
    }

    public function test_inventory_turnover_uses_material_issue_cost_and_weighted_average_stock(): void
    {
        $categoryId = DB::table('item_categories')->insertGetId([
            'name' => 'KPI Raw Materials', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('items')->insertGetId([
            'code' => 'KPI-RAW-001', 'name' => 'KPI Resin', 'category_id' => $categoryId,
            'item_type' => 'raw_material', 'unit_of_measure' => 'kg', 'standard_cost' => '10.0000',
            'reorder_method' => 'manual', 'reorder_point' => 0, 'safety_stock' => 0,
            'minimum_order_quantity' => 1, 'lead_time_days' => 0, 'is_critical' => false,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'name' => 'KPI Warehouse', 'code' => 'KPI-WH', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $zoneId = DB::table('warehouse_zones')->insertGetId([
            'warehouse_id' => $warehouseId, 'name' => 'KPI Zone', 'code' => 'KPI',
            'zone_type' => 'raw_materials', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $locationId = DB::table('warehouse_locations')->insertGetId([
            'zone_id' => $zoneId, 'code' => 'KPI-LOC', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('stock_levels')->insert([
            'item_id' => $itemId, 'location_id' => $locationId, 'quantity' => '100.000',
            'reserved_quantity' => '0.000', 'weighted_avg_cost' => '10.0000', 'lock_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stock_movements')->insert([
            'item_id' => $itemId, 'from_location_id' => $locationId, 'movement_type' => 'material_issue',
            'quantity' => '10.000', 'unit_cost' => '5.0000', 'total_cost' => '50.00',
            'created_at' => '2026-07-15 12:00:00',
        ]);

        $definition = KpiDefinition::query()->create([
            'code' => 'test_inventory_turnover', 'name' => 'Test Inventory Turnover', 'module' => 'inventory',
            'unit' => 'ratio', 'direction' => 'higher_is_better', 'target_value' => 6,
            'calculation_method' => 'computeInventoryTurnover', 'is_active' => true, 'display_order' => 1,
        ]);

        $snapshot = app(KpiSnapshotService::class)->computeKpi($definition, 2026, 7);

        $this->assertNotNull($snapshot);
        $this->assertSame('0.6000', (string) $snapshot->actual_value);
    }
}
