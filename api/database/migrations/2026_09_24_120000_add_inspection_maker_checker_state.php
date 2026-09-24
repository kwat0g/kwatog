<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_STATUSES = ['draft', 'in_progress', 'passed', 'failed', 'cancelled'];

    private const STATUSES = ['draft', 'in_progress', 'awaiting_review', 'passed', 'failed', 'cancelled'];

    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table): void {
            $table->string('proposed_result', 10)->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('inspector_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_remarks')->nullable()->after('reviewed_at');
        });

        $this->replaceGuard('inspections', 'status', 'inspections_status_check', self::STATUSES);
        $this->addGuard('inspections', 'proposed_result', 'inspections_proposed_result_check', ['passed', 'failed'], nullable: true);
    }

    public function down(): void
    {
        $this->dropGuard('inspections', 'inspections_status_check');
        $this->dropGuard('inspections', 'inspections_proposed_result_check');
        $this->addGuard('inspections', 'status', 'inspections_status_check', self::OLD_STATUSES);

        Schema::table('inspections', function (Blueprint $table): void {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['proposed_result', 'reviewed_by', 'reviewed_at', 'review_remarks']);
        });
    }

    /** @param list<string> $allowed */
    private function replaceGuard(string $table, string $column, string $name, array $allowed): void
    {
        $this->dropGuard($table, $name);
        $this->addGuard($table, $column, $name, $allowed);
    }

    /** @param list<string> $allowed */
    private function addGuard(string $table, string $column, string $name, array $allowed, bool $nullable = false): void
    {
        $values = implode(', ', array_map(static fn (string $value): string => "'".str_replace("'", "''", $value)."'", $allowed));
        $condition = ($nullable ? $column.' IS NULL OR ' : '').$column.' IN ('.$values.')';

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE \"{$table}\" ADD CONSTRAINT \"{$name}\" CHECK ({$condition})");
            return;
        }

        DB::statement("CREATE TRIGGER {$name}_insert_guard BEFORE INSERT ON {$table} WHEN NOT ({$condition}) BEGIN SELECT RAISE(ABORT, 'invalid {$table}.{$column}'); END");
        DB::statement("CREATE TRIGGER {$name}_update_guard BEFORE UPDATE OF {$column} ON {$table} WHEN NOT ({$condition}) BEGIN SELECT RAISE(ABORT, 'invalid {$table}.{$column}'); END");
    }

    private function dropGuard(string $table, string $name): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$name}\"");
            return;
        }

        DB::statement("DROP TRIGGER IF EXISTS {$name}_insert_guard");
        DB::statement("DROP TRIGGER IF EXISTS {$name}_update_guard");
    }
};
