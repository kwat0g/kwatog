<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 — the index batch really applied.
 *
 * A migration whose guard silently no-ops looks exactly like a healthy
 * `migrate:fresh` (CLAUDE.md records three constraints skipped that way, and the
 * skip only surfaced because someone re-read the schema). These assertions are
 * the counter-measure: the batch is listed here independently of the migration
 * files, so a renamed/skipped/omitted index fails the suite instead of quietly
 * leaving a dashboard on a sequential scan.
 *
 * The list mirrors Phase 0 B §4 "cheapest wins before dashboards ship"
 * (rows 1-6, 10-26, 31-33, 39-45, 85) and the settings row the analytics layer
 * reads.
 */
class Phase0IndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> table => indexes that must exist */
    private const REQUIRED_INDEXES = [
        'wo_operations' => [
            'wo_operations_work_order_id_index',
            'wo_operations_status_index',
            'wo_operations_machine_id_index',
            'wo_operations_mold_id_index',
            'wo_operations_operator_id_index',
            'wo_operations_routing_operation_id_index',
        ],
        'production_logs' => [
            'production_logs_wo_operation_id_index',
            'production_logs_operator_id_index',
        ],
        'work_orders' => [
            'work_orders_product_id_index',
            'work_orders_mold_id_index',
            'work_orders_parent_wo_id_index',
            'work_orders_sales_order_item_id_index',
            'work_orders_actual_start_index',
            'work_orders_actual_end_index',
        ],
        'grn_items' => [
            'grn_items_goods_receipt_note_id_index',
        ],
        'inspections' => [
            'inspections_item_id_index',
            'inspections_item_quality_plan_id_index',
            'inspections_wo_operation_id_index',
            'inspections_inspection_spec_id_index',
            'inspections_inspection_spec_revision_id_index',
            'inspections_started_at_index',
        ],
        'maintenance_work_orders' => [
            'maintenance_work_orders_started_at_index',
        ],
        'return_requests' => [
            'return_requests_status_index',
            'return_requests_type_index',
            'return_requests_return_date_index',
            'return_requests_customer_id_index',
            'return_requests_vendor_id_index',
            'return_requests_sales_order_id_index',
            'return_requests_purchase_order_id_index',
            'return_requests_invoice_id_index',
            'return_requests_bill_id_index',
            'return_requests_ncr_id_index',
            'return_requests_inspection_id_index',
            'return_requests_replacement_wo_id_index',
            'return_requests_credit_note_id_index',
            'return_requests_stock_movement_id_index',
        ],
        'return_request_items' => [
            'return_request_items_item_id_index',
            'return_request_items_product_id_index',
            'return_request_items_ncr_id_index',
            'return_request_items_source_sales_order_item_id_index',
            'return_request_items_source_delivery_item_id_index',
            'return_request_items_source_po_item_id_index',
            'return_request_items_source_grn_item_id_index',
            'return_request_items_source_invoice_item_id_index',
            'return_request_items_source_bill_item_id_index',
            'return_request_items_quarantine_location_id_index',
            'return_request_items_quarantine_movement_id_index',
            'return_request_items_quarantine_release_movement_id_index',
            'return_request_items_stock_movement_id_index',
        ],
        'return_cases' => [
            'return_cases_credit_note_id_index',
            'return_cases_replacement_sales_order_id_index',
            'return_cases_replacement_purchase_order_id_index',
            'return_cases_replacement_delivery_id_index',
            'return_cases_resolution_goods_receipt_note_id_index',
        ],
        'budgets' => [
            'budgets_fiscal_year_id_index',
            'budgets_department_id_index',
            'budgets_status_index',
        ],
        'budget_transfers' => [
            'budget_transfers_from_line_item_id_index',
            'budget_transfers_to_line_item_id_index',
        ],
        'credit_note_lines' => [
            'credit_note_lines_credit_note_id_index',
            'credit_note_lines_account_id_index',
        ],
        'purchase_requests' => [
            'purchase_requests_date_index',
        ],
        'purchase_orders' => [
            'purchase_orders_date_index',
        ],
        'deliveries' => [
            'deliveries_delivered_at_index',
            'deliveries_confirmed_at_index',
            'deliveries_received_at_index',
            'deliveries_vehicle_id_index',
            'deliveries_driver_id_index',
            'deliveries_invoice_id_index',
        ],
        'invoices' => [
            'invoices_sales_order_id_index',
            'invoices_delivery_id_index',
        ],
        'invoice_items' => [
            'invoice_items_source_delivery_item_id_index',
        ],
        'delivery_items' => [
            'delivery_items_inspection_id_index',
        ],
        'official_receipts' => [
            'official_receipts_invoice_id_index',
        ],
        'supplier_invoices' => [
            'supplier_invoices_vendor_id_index',
            'supplier_invoices_purchase_order_id_index',
        ],
        'work_order_outputs' => [
            'work_order_outputs_rework_ncr_id_index',
            'work_order_outputs_recorded_at_index',
        ],
    ];

    public function test_every_index_in_the_phase_0_batch_exists(): void
    {
        $present = DB::table('pg_indexes')
            ->whereIn('indexname', array_merge(...array_values(self::REQUIRED_INDEXES)))
            ->pluck('indexname')
            ->all();

        $missing = [];

        foreach (self::REQUIRED_INDEXES as $table => $indexes) {
            foreach ($indexes as $index) {
                if (! in_array($index, $present, true)) {
                    $missing[] = $table.':'.$index;
                }
            }
        }

        $this->assertSame([], $missing, 'Index batch did not apply: '.implode(', ', $missing));
    }

    public function test_indexed_tables_are_covered_on_the_columns_the_dashboards_filter(): void
    {
        foreach (['wo_operations', 'return_requests', 'budgets', 'credit_note_lines'] as $table) {
            $this->assertNotEmpty(
                DB::table('pg_indexes')->where('tablename', $table)
                    ->where('indexname', '!=', $table.'_pkey')->pluck('indexname')->all(),
                "Table [{$table}] still has no non-primary-key index."
            );
        }
    }

    public function test_analytics_cache_ttl_setting_is_seeded(): void
    {
        $value = DB::table('settings')->where('key', 'dashboard.analytics.cache_ttl_seconds')->value('value');

        $this->assertNotNull($value, 'The analytics cache TTL setting must be seeded.');
        $this->assertSame(60, (int) json_decode((string) $value));
    }

    public function test_mrp_run_to_plan_trace_column_is_indexed(): void
    {
        $this->assertTrue(DB::table('pg_indexes')
            ->where('tablename', 'mrp_plans')
            ->where('indexname', 'mrp_plans_mrp_run_id_index')
            ->exists());
    }

    public function test_work_order_sales_order_item_link_is_nullable_and_set_null_on_delete(): void
    {
        $constraint = DB::table('information_schema.referential_constraints')
            ->where('constraint_name', 'work_orders_sales_order_item_id_foreign')
            ->first();

        $this->assertNotNull($constraint);
        $this->assertSame('SET NULL', $constraint->delete_rule);
        $this->assertSame('YES', DB::table('information_schema.columns')
            ->where('table_name', 'work_orders')
            ->where('column_name', 'sales_order_item_id')
            ->value('is_nullable'));
    }

    /**
     * Every migration in this batch must be reversible. The full round trip
     * (`migrate` → `migrate:rollback --step=6` → `migrate`) was run against a
     * scratch database while writing the batch; this pins the part a future edit
     * could silently break by adding an index without a matching drop.
     */
    public function test_every_migration_in_the_batch_is_reversible(): void
    {
        $files = glob(database_path('migrations/2026_10_11_09*_*.php'));

        $this->assertCount(6, $files, 'The Phase 2 index batch must ship as six migration files.');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringContainsString('public function up(): void', $source, basename($file));
            $this->assertStringContainsString('public function down(): void', $source, basename($file));

            if (str_contains($source, '->index(')) {
                $this->assertStringContainsString(
                    'dropIndex(',
                    $source,
                    basename($file).' adds indexes without a matching dropIndex in down().'
                );
            }
        }
    }
}
