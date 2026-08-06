<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'departments',
        'positions',
        'trainings',
        'employee_skills',
        'employee_property',
        'employee_documents',
        'skills',
        'item_categories',
        'warehouses',
        'warehouse_zones',
        'warehouse_locations',
        'uoms',
        'shifts',
        'holidays',
        'attendances',
        'leave_types',
        'leave_requests',
        'sales_orders',
        'sales_order_items',
        'product_price_agreements',
        'purchase_requests',
        'purchase_request_items',
        'purchase_request_templates',
        'purchase_orders',
        'purchase_order_items',
        'approved_suppliers',
        'work_orders',
        'work_order_materials',
        'shipments',
        'shipment_documents',
        'containers',
        'vehicles',
        'deliveries',
        'delivery_proofs',
        'journal_entries',
        'journal_entry_lines',
        'inspection_specs',
        'inspection_spec_items',
        'ncr_templates',
        'bill_of_materials',
        'government_contribution_tables',
        'de_minimis_benefits',
        'payroll_disbursement_proofs',
        'scheduled_exports',
        'roles',
        'user_permission_overrides',
        'quotes',
        'quote_items',
        'product_routings',
        'routing_operations',
        'wo_operations',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};