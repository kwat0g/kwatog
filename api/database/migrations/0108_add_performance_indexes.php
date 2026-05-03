<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — Task 82. Performance indexes audit.
 *
 * Adds composite indexes on the join + filter columns most heavily exercised
 * by dashboards, list pages, and reporting queries. Each addIndex is guarded
 * with hasIndex (Postgres) so re-runs are no-ops, and Schema::hasTable so a
 * partial deployment doesn't blow up.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->safeIndex('attendances',          ['employee_id', 'date'],                'attendances_emp_date_idx');
        $this->safeIndex('payrolls',             ['payroll_period_id', 'employee_id'],   'payrolls_period_emp_idx');
        $this->safeIndex('journal_entry_lines',  ['account_id', 'journal_entry_id'],     'jel_acct_je_idx');
        $this->safeIndex('stock_movements',      ['item_id', 'created_at'],              'stock_movements_item_created_idx');
        $this->safeIndex('audit_logs',           ['model_type', 'model_id', 'created_at'],'audit_logs_model_created_idx');
        $this->safeIndex('notifications',        ['notifiable_id', 'read_at'],           'notifications_notifiable_read_idx');
        $this->safeIndex('work_orders',          ['status', 'planned_start'],            'wo_status_plan_idx');
        $this->safeIndex('inspections',          ['stage', 'status'],                    'inspections_stage_status_idx');
    }

    public function down(): void
    {
        $this->safeDropIndex('attendances',         'attendances_emp_date_idx');
        $this->safeDropIndex('payrolls',            'payrolls_period_emp_idx');
        $this->safeDropIndex('journal_entry_lines', 'jel_acct_je_idx');
        $this->safeDropIndex('stock_movements',     'stock_movements_item_created_idx');
        $this->safeDropIndex('audit_logs',          'audit_logs_model_created_idx');
        $this->safeDropIndex('notifications',       'notifications_notifiable_read_idx');
        $this->safeDropIndex('work_orders',         'wo_status_plan_idx');
        $this->safeDropIndex('inspections',         'inspections_stage_status_idx');
    }

    private function safeIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table)) return;
        foreach ($columns as $col) {
            if (! Schema::hasColumn($table, $col)) return;
        }
        if ($this->indexExists($table, $name)) return;
        Schema::table($table, function (Blueprint $t) use ($columns, $name) {
            $t->index($columns, $name);
        });
    }

    private function safeDropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) return;
        Schema::table($table, function (Blueprint $t) use ($name) {
            $t->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        $driver = DB::connection()->getDriverName();
        return match ($driver) {
            'pgsql' => (bool) DB::selectOne(
                'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
                [$table, $name],
            ),
            'mysql' => (bool) DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, $name],
            ),
            'sqlite' => (bool) DB::selectOne(
                "SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name = ? AND name = ?",
                [$table, $name],
            ),
            default => false,
        };
    }
};
