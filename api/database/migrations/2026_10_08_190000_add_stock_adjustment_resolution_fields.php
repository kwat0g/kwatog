<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'stock_adjustments';

    private const STATUS_GUARD = 'stock_adjustments_status_check';

    private const RESOLUTION_GUARD = 'stock_adjustments_resolution_check';

    private const LEGACY_STATUSES = ['pending', 'approved'];

    private const STATUSES = ['pending', 'approved', 'rejected', 'withdrawn'];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $driver = $this->driver();
        $invalid = DB::table(self::TABLE)
            ->whereNull('status')
            ->orWhereNotIn('status', self::STATUSES)
            ->exists();
        if ($invalid) {
            throw new RuntimeException('Cannot expand stock adjustment statuses while unsupported status values exist.');
        }

        Schema::table(self::TABLE, static function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'resolved_by')) {
                $table->foreignId('resolved_by')->nullable()->constrained('users');
            }
            if (! Schema::hasColumn(self::TABLE, 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable();
            }
            if (! Schema::hasColumn(self::TABLE, 'resolution_reason')) {
                $table->text('resolution_reason')->nullable();
            }
        });

        $this->dropGuards($driver);
        $this->installStatusGuard($driver, self::STATUSES);
        $this->installResolutionGuard($driver);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $hasResolutionColumns = Schema::hasColumn(self::TABLE, 'resolved_by')
            && Schema::hasColumn(self::TABLE, 'resolved_at')
            && Schema::hasColumn(self::TABLE, 'resolution_reason');
        if ($hasResolutionColumns && DB::table(self::TABLE)
            ->whereIn('status', ['rejected', 'withdrawn'])
            ->orWhereNotNull('resolved_by')
            ->orWhereNotNull('resolved_at')
            ->orWhereNotNull('resolution_reason')
            ->exists()) {
            throw new RuntimeException(
                'Cannot roll back stock adjustment resolution while resolved records exist; preserve their status and audit evidence.'
            );
        }

        $driver = $this->driver();
        $this->dropGuards($driver);

        if ($hasResolutionColumns) {
            Schema::table(self::TABLE, static function (Blueprint $table): void {
                $table->dropForeign(['resolved_by']);
                $table->dropColumn(['resolved_by', 'resolved_at', 'resolution_reason']);
            });
        }

        $this->installStatusGuard($driver, self::LEGACY_STATUSES);
    }

    private function driver(): string
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Stock adjustment lifecycle guards require PostgreSQL or SQLite; received {$driver}.");
        }

        return $driver;
    }

    private function dropGuards(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "'.self::TABLE.'" DROP CONSTRAINT IF EXISTS "'.self::STATUS_GUARD.'"');
            DB::statement('ALTER TABLE "'.self::TABLE.'" DROP CONSTRAINT IF EXISTS "'.self::RESOLUTION_GUARD.'"');

            return;
        }

        foreach ([self::STATUS_GUARD, self::RESOLUTION_GUARD] as $name) {
            DB::statement('DROP TRIGGER IF EXISTS '.$name.'_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS '.$name.'_update_guard');
        }
    }

    /** @param list<string> $statuses */
    private function installStatusGuard(string $driver, array $statuses): void
    {
        $values = $this->sqlValues($statuses);
        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE "'.self::TABLE.'" ADD CONSTRAINT "'.self::STATUS_GUARD.'" '
                .'CHECK ("status" IN ('.$values.'))'
            );

            return;
        }

        $this->createSqliteGuard(
            self::STATUS_GUARD,
            'NEW."status" IS NOT NULL AND NEW."status" NOT IN ('.$values.')',
            'invalid stock_adjustments.status',
        );
    }

    private function installResolutionGuard(string $driver): void
    {
        $validResolution = "(status IN ('pending', 'approved') AND resolved_by IS NULL AND resolved_at IS NULL AND resolution_reason IS NULL)"
            ." OR (status = 'rejected' AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND resolution_reason IS NOT NULL AND length(trim(resolution_reason)) > 0)"
            ." OR (status = 'withdrawn' AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND resolution_reason IS NULL)";
        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE "'.self::TABLE.'" ADD CONSTRAINT "'.self::RESOLUTION_GUARD.'" '
                .'CHECK ('.$validResolution.')'
            );

            return;
        }

        $sqliteExpression = str_replace(
            ['status', 'resolved_at', 'resolution_reason'],
            ['NEW."status"', 'NEW."resolved_at"', 'NEW."resolution_reason"'],
            $validResolution,
        );
        $this->createSqliteGuard(
            self::RESOLUTION_GUARD,
            'NOT ('.$sqliteExpression.')',
            'invalid stock_adjustments resolution',
        );
    }

    private function createSqliteGuard(string $name, string $condition, string $message): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::statement(
                'CREATE TRIGGER '.$name.'_'.$suffix.'_guard '
                .'BEFORE '.$event.' ON "'.self::TABLE.'" '
                .'WHEN '.$condition.' '
                .'BEGIN SELECT RAISE(ABORT, '.DB::getPdo()->quote($message).'); END'
            );
        }
    }

    /** @param list<string> $values */
    private function sqlValues(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'".$value."'", $values));
    }
};
