<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\User;
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

    /**
     * The shipped catalog must actually compute. This is the M007-10 guard: the
     * synthetic test above proves exception AGGREGATION, not that any real
     * calculator matches the schema, and three column-name defects (`dppm`,
     * `budget_utilization`, `wo_completion_rate`) shipped behind that gap.
     *
     * DIAGNOSING A FAILURE HERE — READ THIS FIRST. `computeAll` catches per
     * definition but does NOT open a savepoint per definition, and
     * RefreshDatabase wraps the test in one transaction. So the FIRST real SQL
     * error aborts the transaction and every later definition reports the
     * cascade `SQLSTATE[25P02] current transaction is aborted` instead of its
     * own result. Only the first entry in `failed` is diagnostic; fix it, re-run,
     * and repeat until green rather than treating the 25P02 rows as real. The
     * scheduled command runs in autocommit, so in production only the genuinely
     * broken definition fails.
     */
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

    /**
     * DPPM reads `inspections.defect_count`; it used to sum a `defects_found`
     * column that exists nowhere in the schema, which aborted the whole run.
     * 2 defects over a 500-piece sample is 4,000 per million.
     */
    public function test_dppm_uses_the_inspection_defect_count_column(): void
    {
        $productId = DB::table('products')->insertGetId([
            'part_number' => 'KPI-DPPM-01', 'name' => 'KPI Bushing', 'unit_of_measure' => 'pcs',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inspections')->insert([
            // In the period: 300 + 200 sampled, 2 defects total.
            [
                'inspection_number' => 'KPI-QC-0001', 'stage' => 'outgoing', 'status' => 'passed',
                'product_id' => $productId, 'batch_quantity' => 1000, 'sample_size' => 300,
                'defect_count' => 0, 'completed_at' => '2026-07-10 08:00:00',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'inspection_number' => 'KPI-QC-0002', 'stage' => 'outgoing', 'status' => 'failed',
                'product_id' => $productId, 'batch_quantity' => 800, 'sample_size' => 200,
                'defect_count' => 2, 'completed_at' => '2026-07-20 08:00:00',
                'created_at' => now(), 'updated_at' => now(),
            ],
            // Outside the period — must not move the figure.
            [
                'inspection_number' => 'KPI-QC-0003', 'stage' => 'outgoing', 'status' => 'failed',
                'product_id' => $productId, 'batch_quantity' => 800, 'sample_size' => 500,
                'defect_count' => 400, 'completed_at' => '2026-08-02 08:00:00',
                'created_at' => now(), 'updated_at' => now(),
            ],
            // Never completed — completed_at IS NULL, so the BETWEEN excludes it.
            [
                'inspection_number' => 'KPI-QC-0004', 'stage' => 'outgoing', 'status' => 'in_progress',
                'product_id' => $productId, 'batch_quantity' => 800, 'sample_size' => 500,
                'defect_count' => 400, 'completed_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $definition = KpiDefinition::query()->create([
            'code' => 'test_dppm', 'name' => 'Test DPPM', 'module' => 'quality',
            'unit' => 'count', 'direction' => 'lower_is_better', 'target_value' => 500,
            'calculation_method' => 'computeDppm', 'is_active' => true, 'display_order' => 1,
        ]);

        $snapshot = app(KpiSnapshotService::class)->computeKpi($definition, 2026, 7);

        $this->assertNotNull($snapshot);
        $this->assertSame('4000.0000', (string) $snapshot->actual_value);
    }

    /**
     * Budget utilization reads the generated `annual_total` column, not a
     * `budgeted_amount` column that does not exist. 250 actual over 1000
     * budgeted is 25%.
     */
    public function test_budget_utilization_uses_the_generated_annual_total(): void
    {
        $fiscalYearId = DB::table('fiscal_years')->insertGetId([
            'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $budgetId = DB::table('budgets')->insertGetId([
            'fiscal_year_id' => $fiscalYearId, 'budget_type' => 'operating',
            'name' => 'KPI Budget', 'total_allocated' => '1000.00', 'total_spent' => '250.00',
            'total_committed' => '0.00', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $accountId = DB::table('accounts')->insertGetId([
            'code' => '6000-KPI', 'name' => 'KPI Expense', 'type' => 'expense',
            'normal_balance' => 'debit', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // annual_total is GENERATED ALWAYS from the month columns, so it cannot
        // be written directly — 12 months of 100 sums to 1000.
        DB::table('budget_line_items')->insert([
            'budget_id' => $budgetId, 'account_id' => $accountId,
            'jan' => '100.00', 'feb' => '100.00', 'mar' => '100.00', 'apr' => '100.00',
            'may' => '100.00', 'jun' => '100.00', 'jul' => '100.00', 'aug' => '100.00',
            'sep' => '100.00', 'oct' => '0.00', 'nov' => '0.00', 'dec' => '100.00',
            'actual_total' => '250.00', 'variance' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $definition = KpiDefinition::query()->create([
            'code' => 'test_budget_util', 'name' => 'Test Budget Utilization', 'module' => 'accounting',
            'unit' => 'percentage', 'direction' => 'higher_is_better', 'target_value' => 90,
            'calculation_method' => 'computeBudgetUtilization', 'is_active' => true, 'display_order' => 1,
        ]);

        $snapshot = app(KpiSnapshotService::class)->computeKpi($definition, 2026, 7);

        $this->assertNotNull($snapshot);
        $this->assertSame('25.0000', (string) $snapshot->actual_value);
    }

    /**
     * WO completion rate reads `planned_end` for the due-date population; there
     * is no `scheduled_end` column. The in-flight row is `in_progress` — the
     * filter used to name `started`, which the enum does not define and the
     * lifecycle check constraint rejects, so running work silently left the
     * denominator. 1 of 2 due work orders finished → 50%; with the old
     * `started` filter the denominator would have been 1 and this read 100%.
     */
    public function test_wo_completion_rate_uses_planned_end_for_the_due_population(): void
    {
        $productId = DB::table('products')->insertGetId([
            'part_number' => 'KPI-WO-01', 'name' => 'KPI Cap', 'unit_of_measure' => 'pcs',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $userId = User::factory()->create()->id;

        $row = fn (string $no, string $status, string $plannedEnd, ?string $actualEnd): array => [
            'wo_number' => $no, 'product_id' => $productId, 'quantity_target' => 100,
            'planned_start' => '2026-07-01 08:00:00', 'planned_end' => $plannedEnd,
            'actual_end' => $actualEnd, 'status' => $status, 'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('work_orders')->insert([
            // Due in July and finished in July → in both populations.
            $row('WO-KPI-0001', 'completed', '2026-07-20 17:00:00', '2026-07-20 16:00:00'),
            // Due in July, still running → denominator only. This is the row
            // the `started` filter dropped.
            $row('WO-KPI-0002', 'in_progress', '2026-07-25 17:00:00', null),
            // Due in August → neither population.
            $row('WO-KPI-0003', 'completed', '2026-08-10 17:00:00', '2026-08-10 16:00:00'),
            // Planned but not yet released → outside the filtered statuses.
            $row('WO-KPI-0004', 'planned', '2026-07-28 17:00:00', null),
        ]);

        $definition = KpiDefinition::query()->create([
            'code' => 'test_wo_completion', 'name' => 'Test WO Completion', 'module' => 'production',
            'unit' => 'percentage', 'direction' => 'higher_is_better', 'target_value' => 95,
            'calculation_method' => 'computeWoCompletionRate', 'is_active' => true, 'display_order' => 1,
        ]);

        $snapshot = app(KpiSnapshotService::class)->computeKpi($definition, 2026, 7);

        $this->assertNotNull($snapshot);
        $this->assertSame('50.0000', (string) $snapshot->actual_value);
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
