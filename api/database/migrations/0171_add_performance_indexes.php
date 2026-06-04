<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes — second audit pass.
 *
 * Migration 0108 covered the first wave. This pass adds the indexes that were
 * still missing after reviewing every high-traffic query path:
 *
 *   payrolls            (payroll_period_id, computed_at)
 *     → "which employees still need computing for this period?" filter
 *
 *   audit_logs          (user_id, created_at)
 *     → user activity timeline; 0108 added (model_type, model_id, created_at)
 *       but user-centric queries had no covering index
 *
 *   approval_records    (approvable_type, approvable_id, action)
 *     → approval-board hot path; 0010 has (approvable_type, approvable_id)
 *       and a bare action index but no three-column composite so the DB
 *       cannot satisfy the filter in a single scan
 *
 *   notifications       (notifiable_type, notifiable_id, read_at)
 *     → unread-badge count; 0108 has (notifiable_id, read_at) but omits
 *       notifiable_type, causing a type-mismatch filter on every badge query
 *
 *   stock_levels        (item_id, quantity)
 *     → reorder-point sweep joins items on item_id then filters by quantity;
 *       the existing bare item_id index is insufficient for a covering scan
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->safeIndex('payrolls',          ['payroll_period_id', 'computed_at'],              'payrolls_period_computed_idx');
        $this->safeIndex('audit_logs',        ['user_id', 'created_at'],                         'audit_logs_user_created_idx');
        $this->safeIndex('approval_records',  ['approvable_type', 'approvable_id', 'action'],    'approval_records_entity_action_idx');
        $this->safeIndex('notifications',     ['notifiable_type', 'notifiable_id', 'read_at'],   'notifications_notifiable_type_read_idx');
        $this->safeIndex('stock_levels',      ['item_id', 'quantity'],                           'stock_levels_item_qty_idx');
    }

    public function down(): void
    {
        $this->safeDropIndex('payrolls',         'payrolls_period_computed_idx');
        $this->safeDropIndex('audit_logs',       'audit_logs_user_created_idx');
        $this->safeDropIndex('approval_records', 'approval_records_entity_action_idx');
        $this->safeDropIndex('notifications',    'notifications_notifiable_type_read_idx');
        $this->safeDropIndex('stock_levels',     'stock_levels_item_qty_idx');
    }

    private function safeIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        foreach ($columns as $col) {
            if (! Schema::hasColumn($table, $col)) {
                return;
            }
        }

        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns, $name) {
            $t->index($columns, $name);
        });
    }

    private function safeDropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($name) {
            $t->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql'  => (bool) DB::selectOne(
                'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?',
                [$table, $name],
            ),
            'mysql'  => (bool) DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$table, $name],
            ),
            'sqlite' => (bool) DB::selectOne(
                "SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name = ? AND name = ?",
                [$table, $name],
            ),
            default  => false,
        };
    }
};
