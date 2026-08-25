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
        if (Schema::hasTable('mrp_plans')) {
            Schema::table('mrp_plans', function (Blueprint $table): void {
                if (! Schema::hasColumn('mrp_plans', 'mrp_run_id')) {
                    $table->foreignId('mrp_run_id')->nullable()->after('generated_by')
                        ->constrained('mrp_runs')->nullOnDelete();
                }
                if (! Schema::hasColumn('mrp_plans', 'generation_context')) {
                    $table->json('generation_context')->nullable()->after('cost_summary');
                }
            });
        }

        if (Schema::hasTable('mrp_runs')) {
            Schema::table('mrp_runs', function (Blueprint $table): void {
                if (! Schema::hasColumn('mrp_runs', 'failed_sales_orders')) {
                    $table->unsignedInteger('failed_sales_orders')->default(0)->after('plans_generated');
                }
                if (! Schema::hasColumn('mrp_runs', 'error_code')) {
                    $table->string('error_code', 80)->nullable()->after('error_message');
                }
                if (! Schema::hasColumn('mrp_runs', 'recovery_action')) {
                    $table->text('recovery_action')->nullable()->after('error_code');
                }
            });

            $this->replaceRunStatusGuard(DB::getDriverName(), ['running', 'completed', 'failed', 'partial']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mrp_runs')) {
            DB::table('mrp_runs')->where('status', 'partial')->update(['status' => 'failed']);
            $this->replaceRunStatusGuard(DB::getDriverName(), ['running', 'completed', 'failed']);

            Schema::table('mrp_runs', function (Blueprint $table): void {
                foreach (['recovery_action', 'error_code', 'failed_sales_orders'] as $column) {
                    if (Schema::hasColumn('mrp_runs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('mrp_plans')) {
            Schema::table('mrp_plans', function (Blueprint $table): void {
                if (Schema::hasColumn('mrp_plans', 'mrp_run_id')) {
                    $table->dropForeign(['mrp_run_id']);
                    $table->dropColumn('mrp_run_id');
                }
                if (Schema::hasColumn('mrp_plans', 'generation_context')) {
                    $table->dropColumn('generation_context');
                }
            });
        }
    }

    /** @param list<string> $allowed */
    private function replaceRunStatusGuard(string $driver, array $allowed): void
    {
        $values = implode(', ', array_map(static fn (string $value): string => "'{$value}'", $allowed));
        $name = 'mrp_runs_status_lifecycle_check';

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "mrp_runs" DROP CONSTRAINT IF EXISTS "'.$name.'"');
            DB::statement('ALTER TABLE "mrp_runs" ADD CONSTRAINT "'.$name.'" CHECK ("status" IN ('.$values.') OR "status" IS NULL)');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_insert_guard"');
            DB::statement('DROP TRIGGER IF EXISTS "'.$name.'_update_guard"');
            DB::statement('CREATE TRIGGER "'.$name.'_insert_guard" BEFORE INSERT ON "mrp_runs" WHEN NEW."status" IS NOT NULL AND NEW."status" NOT IN ('.$values.') BEGIN SELECT RAISE(ABORT, \'invalid mrp_runs.status\'); END');
            DB::statement('CREATE TRIGGER "'.$name.'_update_guard" BEFORE UPDATE OF "status" ON "mrp_runs" WHEN NEW."status" IS NOT NULL AND NEW."status" NOT IN ('.$values.') BEGIN SELECT RAISE(ABORT, \'invalid mrp_runs.status\'); END');

            return;
        }

        throw new RuntimeException("MRP run status constraints require PostgreSQL or SQLite; received {$driver}.");
    }
};
