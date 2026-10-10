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
        Schema::table('inspection_measurements', function (Blueprint $table): void {
            $table->string('item_result', 10)->nullable();
            $table->string('item_problem_description', 500)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE inspection_measurements ADD CONSTRAINT inspection_item_result_check CHECK (item_result IS NULL OR item_result IN ('passed', 'failed'))");
            DB::statement("ALTER TABLE inspection_measurements ADD CONSTRAINT inspection_item_problem_check CHECK (item_result IS DISTINCT FROM 'failed' OR (item_problem_description IS NOT NULL AND length(trim(item_problem_description)) > 0))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inspection_measurements DROP CONSTRAINT IF EXISTS inspection_item_result_check');
            DB::statement('ALTER TABLE inspection_measurements DROP CONSTRAINT IF EXISTS inspection_item_problem_check');
        }
        Schema::table('inspection_measurements', function (Blueprint $table): void {
            $table->dropColumn(['item_result', 'item_problem_description']);
        });
    }
};
