<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'supplier_performance_snapshots_period_check';

    private const INSERT_TRIGGER = 'supplier_performance_snapshots_period_insert';

    private const UPDATE_TRIGGER = 'supplier_performance_snapshots_period_update';

    public function up(): void
    {
        if (! Schema::hasTable('supplier_performance_snapshots')) {
            return;
        }

        $invalid = DB::table('supplier_performance_snapshots')
            ->where(function ($query): void {
                $query
                    ->where('period_year', '<', 2000)
                    ->orWhere('period_year', '>', 2100)
                    ->orWhere('period_month', '<', 1)
                    ->orWhere('period_month', '>', 12);
            })
            ->first(['id', 'period_year', 'period_month']);

        if ($invalid !== null) {
            throw new RuntimeException(sprintf(
                'Cannot guard supplier performance periods: snapshot %s has %s-%s.',
                $invalid->id,
                $invalid->period_year,
                $invalid->period_month,
            ));
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE supplier_performance_snapshots ADD CONSTRAINT %s CHECK (period_year BETWEEN 2000 AND 2100 AND period_month BETWEEN 1 AND 12)',
                self::CONSTRAINT,
            ));
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement(sprintf(
                'CREATE TRIGGER IF NOT EXISTS %s BEFORE INSERT ON supplier_performance_snapshots WHEN NEW.period_year < 2000 OR NEW.period_year > 2100 OR NEW.period_month < 1 OR NEW.period_month > 12 BEGIN SELECT RAISE(ABORT, \'Supplier performance period is outside the supported range.\'); END',
                self::INSERT_TRIGGER,
            ));
            DB::statement(sprintf(
                'CREATE TRIGGER IF NOT EXISTS %s BEFORE UPDATE OF period_year, period_month ON supplier_performance_snapshots WHEN NEW.period_year < 2000 OR NEW.period_year > 2100 OR NEW.period_month < 1 OR NEW.period_month > 12 BEGIN SELECT RAISE(ABORT, \'Supplier performance period is outside the supported range.\'); END',
                self::UPDATE_TRIGGER,
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_performance_snapshots DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS '.self::INSERT_TRIGGER);
            DB::statement('DROP TRIGGER IF EXISTS '.self::UPDATE_TRIGGER);
        }
    }
};
