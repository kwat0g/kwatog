<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-033 follow-up: close the remaining high-risk enum-backed lifecycle roots.
 *
 * This migration is additive and deliberately fail-closed.  It inventories
 * existing values before each guard and never rewrites or deletes rows.
 * PostgreSQL receives CHECK constraints; SQLite receives equivalent aborting
 * triggers because SQLite cannot ALTER TABLE ... ADD CONSTRAINT.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> */
    private const STATUSES = [
        'bills' => ['status' => ['draft', 'unpaid', 'partial', 'paid', 'cancelled']],
        'invoices' => ['status' => ['draft', 'finalized', 'partial', 'paid', 'cancelled']],
        'credit_notes' => ['status' => ['draft', 'finalized', 'applied', 'void']],
        'accounting_periods' => ['status' => ['open', 'closed', 'reopened']],
        'budgets' => ['status' => ['draft', 'submitted', 'approved', 'active', 'closed']],
        'purchase_orders' => ['status' => ['draft', 'pending_approval', 'approved', 'sent', 'partially_received', 'received', 'closed', 'cancelled']],
        'purchase_requests' => [
            'status' => ['draft', 'pending', 'approved', 'rejected', 'converted', 'cancelled'],
            'po_conversion_status' => ['not_started', 'pending', 'manual_required', 'converted'],
        ],
        'goods_receipt_notes' => [
            'status' => ['draft', 'pending_qc', 'accepted', 'partial_accepted', 'rejected'],
            'incoming_qc_handoff_status' => ['not_started', 'generated', 'manual_required', 'not_required'],
        ],
        'material_issue_slips' => ['status' => ['draft', 'issued', 'cancelled']],
        'transfer_orders' => ['status' => ['pending', 'transferred', 'completed', 'cancelled']],
        'material_reservations' => ['status' => ['reserved', 'issued', 'released']],
        'mrp_plans' => ['status' => ['active', 'superseded', 'cancelled']],
        'mrp_runs' => ['status' => ['running', 'completed', 'failed']],
        'machines' => ['status' => ['running', 'idle', 'maintenance', 'breakdown', 'offline']],
        'molds' => ['status' => ['available', 'in_use', 'maintenance', 'retired']],
        'work_orders' => ['status' => ['planned', 'confirmed', 'in_progress', 'paused', 'completed', 'closed', 'cancelled']],
        'wo_operations' => ['status' => ['pending', 'setup', 'in_progress', 'paused', 'completed', 'skipped']],
        'production_schedules' => ['status' => ['pending', 'confirmed', 'superseded', 'executed']],
        'non_conformance_reports' => [
            'status' => ['open', 'in_progress', 'closed', 'cancelled'],
            'effectiveness_status' => ['pending_verification', 'effective', 'ineffective', 'not_applicable'],
        ],
        'ppap_submissions' => ['status' => ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'expired']],
        'ppap_elements' => ['status' => ['pending', 'submitted', 'accepted', 'rejected', 'not_applicable']],
        'maintenance_work_orders' => ['status' => ['open', 'assigned', 'in_progress', 'completed', 'cancelled']],
        'assets' => ['status' => ['active', 'under_maintenance', 'disposed']],
        'asset_transfers' => ['status' => ['pending', 'approved', 'rejected', 'completed']],
        'employees' => ['status' => ['active', 'on_leave', 'suspended', 'resigned', 'terminated', 'retired']],
        'clearances' => ['status' => ['pending', 'in_progress', 'completed', 'finalized', 'cancelled']],
        'employee_property' => ['status' => ['issued', 'returned', 'lost']],
        'employee_trainings' => ['status' => ['scheduled', 'completed', 'expired', 'cancelled']],
        'profile_update_requests' => ['status' => ['pending', 'pending_finance', 'approved', 'rejected']],
        'salary_adjustments' => ['status' => ['pending', 'approved', 'rejected']],
        'job_postings' => ['status' => ['draft', 'open', 'closed', 'filled']],
        'leave_requests' => ['status' => ['pending_dept', 'pending_hr', 'approved', 'rejected', 'cancelled']],
        'overtime_requests' => ['status' => ['pending', 'approved', 'rejected']],
        'attendances' => ['status' => ['present', 'absent', 'late', 'halfday', 'on_leave', 'holiday', 'rest_day']],
        'employee_loans' => ['status' => ['pending', 'active', 'paid', 'cancelled', 'rejected']],
        'payroll_adjustments' => ['status' => ['pending', 'approved', 'rejected', 'applied']],
        'kpi_snapshots' => ['status' => ['on_target', 'warning', 'off_target']],
        'newsletter_subscribers' => ['status' => ['subscribed', 'unsubscribed']],
        'contact_inquiries' => ['status' => ['new', 'in_progress', 'closed']],
        'shipments' => ['status' => ['ordered', 'shipped', 'in_transit', 'customs', 'cleared', 'received', 'cancelled']],
        'sales_orders' => ['status' => ['draft', 'confirmed', 'in_production', 'partially_delivered', 'delivered', 'invoiced', 'cancelled']],
        'customer_complaints' => [
            'status' => ['open', 'investigating', 'resolved', 'closed', 'cancelled'],
            'ncr_handoff_status' => ['not_started', 'generated', 'manual_required'],
        ],
        'return_requests' => [
            'status' => ['draft', 'pending_approval', 'approved', 'received', 'inspected', 'completed', 'rejected', 'cancelled'],
            'inspection_handoff_status' => ['not_started', 'generated', 'manual_required', 'not_required'],
        ],
        'return_request_items' => ['quarantine_status' => ['held', 'released', 'scrapped']],
        'stock_movements' => ['gl_handoff_status' => ['not_started', 'generated', 'manual_required', 'not_required']],
        'work_order_outputs' => ['production_receipt_handoff_status' => ['not_started', 'generated', 'manual_required', 'not_required']],
        'deliveries' => [
            'invoice_handoff_status' => ['not_started', 'generated', 'manual_required'],
        ],
        'material_review_records' => ['status' => ['held', 'released', 'scrapped', 'returned']],
        'supplier_order_dispatches' => ['status' => ['pending', 'portal_available', 'manual_required', 'confirmed', 'failed', 'cancelled']],
        'calibration_records' => ['status' => ['active', 'due', 'overdue', 'retired']],
        'vehicles' => ['status' => ['available', 'in_use', 'maintenance', 'retired']],
        // Performance reviews were scope-cut by 0457. Keep the enum-backed
        // rating inventory here so a restored table cannot silently drift;
        // Schema::hasTable makes this a no-op on current installations.
        'performance_reviews' => [
            'status' => ['pending', 'in_progress', 'submitted', 'acknowledged'],
            'overall_rating' => ['Outstanding', 'Exceeds Expectations', 'Meets Expectations', 'Needs Improvement', 'Unsatisfactory'],
        ],
    ];

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Lifecycle status constraints require PostgreSQL or SQLite; received {$driver}.");
        }
        foreach (self::STATUSES as $table => $columns) {
            foreach ($columns as $column => $allowed) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) continue;
                $name = $this->databaseName($table.'_'.$column.'_lifecycle_check');
                if ($this->guardExists($driver, $table, $name)) continue;
                $invalid = DB::table($table)->select($column, DB::raw('COUNT(*) AS row_count'))
                    ->whereNotNull($column)->whereNotIn($column, $allowed)->groupBy($column)->get();
                if ($invalid->isNotEmpty()) {
                    $details = $invalid->map(static fn (object $row): string => "'{$row->{$column}}' ({$row->row_count} rows)")->implode(', ');
                    throw new RuntimeException("Cannot add {$name}: {$table}.{$column} contains unsupported values {$details}; resolve records before retrying. No rows were changed.");
                }
                $values = implode(', ', array_map(static fn (string $value): string => "'".str_replace("'", "''", $value)."'", $allowed));
                if ($driver === 'pgsql') {
                    DB::statement('ALTER TABLE "'.$table.'" ADD CONSTRAINT "'.$name.'" CHECK ("'.$column.'" IN ('.$values.') OR "'.$column.'" IS NULL)');
                } else {
                    DB::statement('CREATE TRIGGER "'.$name.'_insert_guard" BEFORE INSERT ON "'.$table.'" WHEN NEW."'.$column.'" IS NOT NULL AND NEW."'.$column.'" NOT IN ('.$values.') BEGIN SELECT RAISE(ABORT, \'invalid '.$table.'.'.$column.'\'); END');
                    DB::statement('CREATE TRIGGER "'.$name.'_update_guard" BEFORE UPDATE OF "'.$column.'" ON "'.$table.'" WHEN NEW."'.$column.'" IS NOT NULL AND NEW."'.$column.'" NOT IN ('.$values.') BEGIN SELECT RAISE(ABORT, \'invalid '.$table.'.'.$column.'\'); END');
                }
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        foreach (self::STATUSES as $table => $columns) foreach ($columns as $column => $_) {
            $name = $this->databaseName($table.'_'.$column.'_lifecycle_check');
            if ($driver === 'pgsql') DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT IF EXISTS "'.$name.'"');
            elseif ($driver === 'sqlite') {
                DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_insert_guard"');
                DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_update_guard"');
            }
        }
    }

    private function guardExists(string $driver, string $table, string $name): bool
    {
        if ($driver === 'pgsql') return DB::table('pg_constraint as c')->join('pg_class as r', 'r.oid', '=', 'c.conrelid')->where('r.relname', $table)->where('c.conname', $name)->exists();
        return DB::table('sqlite_master')->where('type', 'trigger')->whereIn('name', [$name.'_insert_guard', $name.'_update_guard'])->count() === 2;
    }

    private function databaseName(string $name): string
    {
        // PostgreSQL silently truncates identifiers at 63 bytes. Keep the
        // generated name identical across add/retry/rollback paths.
        return substr($name, 0, 63);
    }
};
