<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('budget_line_items')) {
            return;
        }

        $duplicate = DB::table('budget_line_items')
            ->select('budget_id', 'account_id')
            ->groupBy('budget_id', 'account_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate) {
            throw new RuntimeException(sprintf(
                'Cannot add budget line uniqueness: budget %s contains account %s more than once.',
                $duplicate->budget_id,
                $duplicate->account_id,
            ));
        }

        Schema::table('budget_line_items', function (Blueprint $table): void {
            $table->unique(['budget_id', 'account_id'], 'budget_line_items_budget_account_unique');
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_date_order CHECK (start_date <= end_date)");
            DB::statement("ALTER TABLE budgets ADD CONSTRAINT budgets_nonnegative_allocations CHECK (total_allocated >= 0 AND total_committed >= 0)");
            DB::statement("ALTER TABLE budget_line_items ADD CONSTRAINT budget_line_items_nonnegative_months CHECK (jan >= 0 AND feb >= 0 AND mar >= 0 AND apr >= 0 AND may >= 0 AND jun >= 0 AND jul >= 0 AND aug >= 0 AND sep >= 0 AND oct >= 0 AND nov >= 0 AND dec >= 0)");
        } elseif ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS fiscal_years_date_order_insert
BEFORE INSERT ON fiscal_years
WHEN NEW.start_date > NEW.end_date
BEGIN SELECT RAISE(ABORT, 'Fiscal year start date must not be after end date.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS fiscal_years_date_order_update
BEFORE UPDATE ON fiscal_years
WHEN NEW.start_date > NEW.end_date
BEGIN SELECT RAISE(ABORT, 'Fiscal year start date must not be after end date.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS budgets_nonnegative_allocations_insert
BEFORE INSERT ON budgets
WHEN NEW.total_allocated < 0 OR NEW.total_committed < 0
BEGIN SELECT RAISE(ABORT, 'Budget allocations and commitments must not be negative.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS budgets_nonnegative_allocations_update
BEFORE UPDATE ON budgets
WHEN NEW.total_allocated < 0 OR NEW.total_committed < 0
BEGIN SELECT RAISE(ABORT, 'Budget allocations and commitments must not be negative.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS budget_line_items_nonnegative_months_insert
BEFORE INSERT ON budget_line_items
WHEN NEW.jan < 0 OR NEW.feb < 0 OR NEW.mar < 0 OR NEW.apr < 0 OR NEW.may < 0 OR NEW.jun < 0 OR NEW.jul < 0 OR NEW.aug < 0 OR NEW.sep < 0 OR NEW.oct < 0 OR NEW.nov < 0 OR NEW.dec < 0
BEGIN SELECT RAISE(ABORT, 'Budget line item allocations must not be negative.'); END
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS budget_line_items_nonnegative_months_update
BEFORE UPDATE ON budget_line_items
WHEN NEW.jan < 0 OR NEW.feb < 0 OR NEW.mar < 0 OR NEW.apr < 0 OR NEW.may < 0 OR NEW.jun < 0 OR NEW.jul < 0 OR NEW.aug < 0 OR NEW.sep < 0 OR NEW.oct < 0 OR NEW.nov < 0 OR NEW.dec < 0
BEGIN SELECT RAISE(ABORT, 'Budget line item allocations must not be negative.'); END
SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE fiscal_years DROP CONSTRAINT IF EXISTS fiscal_years_date_order');
            DB::statement('ALTER TABLE budgets DROP CONSTRAINT IF EXISTS budgets_nonnegative_allocations');
            DB::statement('ALTER TABLE budget_line_items DROP CONSTRAINT IF EXISTS budget_line_items_nonnegative_months');
        } elseif ($driver === 'sqlite') {
            foreach ([
                'fiscal_years_date_order_insert', 'fiscal_years_date_order_update',
                'budgets_nonnegative_allocations_insert', 'budgets_nonnegative_allocations_update',
                'budget_line_items_nonnegative_months_insert', 'budget_line_items_nonnegative_months_update',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS "'.$trigger.'"');
            }
        }

        if (Schema::hasTable('budget_line_items')) {
            Schema::table('budget_line_items', function (Blueprint $table): void {
                $table->dropUnique('budget_line_items_budget_account_unique');
            });
        }
    }
};
