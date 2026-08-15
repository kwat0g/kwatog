<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-033 residual inventory for installations that already ran 220000.
 *
 * This is intentionally limited to columns added to the inventory after the
 * original migration was applied. It is additive, preflights all existing
 * non-null values, and never rewrites or deletes rows. Nullable columns keep
 * their NULL allowance through the explicit `IS NULL OR ...` guard.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> */
    private const STATUSES = [
        'budgets' => ['status' => ['draft', 'submitted', 'approved', 'active', 'closed']],
        'goods_receipt_notes' => [
            'incoming_qc_handoff_status' => ['not_started', 'generated', 'manual_required', 'not_required'],
        ],
        'machines' => ['status' => ['running', 'idle', 'maintenance', 'breakdown', 'offline']],
        'molds' => ['status' => ['available', 'in_use', 'maintenance', 'retired']],
        'non_conformance_reports' => [
            'effectiveness_status' => ['pending_verification', 'effective', 'ineffective', 'not_applicable'],
        ],
        'clearances' => ['status' => ['pending', 'in_progress', 'completed', 'finalized', 'cancelled']],
        'employee_property' => ['status' => ['issued', 'returned', 'lost']],
        'profile_update_requests' => ['status' => ['pending', 'pending_finance', 'approved', 'rejected']],
        'salary_adjustments' => ['status' => ['pending', 'approved', 'rejected']],
        'job_postings' => ['status' => ['draft', 'open', 'closed', 'filled']],
        'attendances' => ['status' => ['present', 'absent', 'late', 'halfday', 'on_leave', 'holiday', 'rest_day']],
        'employee_loans' => ['status' => ['pending', 'active', 'paid', 'cancelled', 'rejected']],
        'payroll_adjustments' => ['status' => ['pending', 'approved', 'rejected', 'applied']],
        'kpi_snapshots' => ['status' => ['on_target', 'warning', 'off_target']],
        'newsletter_subscribers' => ['status' => ['subscribed', 'unsubscribed']],
        'contact_inquiries' => ['status' => ['new', 'in_progress', 'closed']],
        'customer_complaints' => [
            'ncr_handoff_status' => ['not_started', 'generated', 'manual_required'],
        ],
        'return_requests' => [
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
        // Scope-cut migration 0457 removes this table on current installs;
        // these guards become active automatically if the feature is restored.
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
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $name = $this->databaseName($table.'_'.$column.'_lifecycle_check');
                if ($this->guardExists($driver, $table, $name)) {
                    continue;
                }

                $invalid = DB::table($table)
                    ->select($column, DB::raw('COUNT(*) AS row_count'))
                    ->whereNotNull($column)
                    ->whereNotIn($column, $allowed)
                    ->groupBy($column)
                    ->orderBy($column)
                    ->get();

                if ($invalid->isNotEmpty()) {
                    $details = $invalid->map(static fn (object $row): string => sprintf(
                        "'%s' (%d rows)",
                        str_replace("'", "''", (string) $row->{$column}),
                        (int) $row->row_count,
                    ))->implode(', ');

                    throw new RuntimeException(
                        "Cannot add {$name}: {$table}.{$column} contains unsupported values {$details}; resolve records before retrying. No rows were changed."
                    );
                }

                $values = implode(', ', array_map(
                    static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
                    $allowed,
                ));

                if ($driver === 'pgsql') {
                    DB::statement(
                        'ALTER TABLE "'.$table.'" ADD CONSTRAINT "'.$name.'" CHECK ("'.$column.'" IN ('.$values.') OR "'.$column.'" IS NULL)'
                    );
                } else {
                    DB::statement(
                        'CREATE TRIGGER "'.$name.'_insert_guard" BEFORE INSERT ON "'.$table.'" '
                        .'WHEN NEW."'.$column.'" IS NOT NULL AND NEW."'.$column.'" NOT IN ('.$values.') '
                        .'BEGIN SELECT RAISE(ABORT, \'invalid '.$table.'.'.$column.'\'); END'
                    );
                    DB::statement(
                        'CREATE TRIGGER "'.$name.'_update_guard" BEFORE UPDATE OF "'.$column.'" ON "'.$table.'" '
                        .'WHEN NEW."'.$column.'" IS NOT NULL AND NEW."'.$column.'" NOT IN ('.$values.') '
                        .'BEGIN SELECT RAISE(ABORT, \'invalid '.$table.'.'.$column.'\'); END'
                    );
                }
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        foreach (self::STATUSES as $table => $columns) {
            foreach ($columns as $column => $_) {
                $name = $this->databaseName($table.'_'.$column.'_lifecycle_check');
                if ($driver === 'pgsql') {
                    DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT IF EXISTS "'.$name.'"');
                } elseif ($driver === 'sqlite') {
                    DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_insert_guard"');
                    DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_update_guard"');
                }
            }
        }
    }

    private function guardExists(string $driver, string $table, string $name): bool
    {
        if ($driver === 'pgsql') {
            return DB::table('pg_constraint as c')
                ->join('pg_class as r', 'r.oid', '=', 'c.conrelid')
                ->where('r.relname', $table)
                ->where('c.conname', $name)
                ->exists();
        }

        return DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', [$name.'_insert_guard', $name.'_update_guard'])
            ->count() === 2;
    }

    private function databaseName(string $name): string
    {
        // PostgreSQL silently truncates identifiers at 63 bytes. Keep the
        // generated name identical across add/retry/rollback paths.
        return substr($name, 0, 63);
    }
};
