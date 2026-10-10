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
        Schema::table('inspections', function (Blueprint $table): void {
            $table->string('recorded_result', 10)->nullable();
            $table->text('problem_description')->nullable();
            $table->unsignedInteger('affected_quantity')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE inspections ADD CONSTRAINT inspections_recorded_result_check CHECK (recorded_result IS NULL OR recorded_result IN ('passed', 'failed'))");
            DB::statement('ALTER TABLE inspections ADD CONSTRAINT inspections_affected_quantity_check CHECK (affected_quantity IS NULL OR (affected_quantity > 0 AND affected_quantity <= batch_quantity))');
            DB::statement("ALTER TABLE inspections ADD CONSTRAINT inspections_problem_description_check CHECK (recorded_result IS DISTINCT FROM 'failed' OR (problem_description IS NOT NULL AND length(trim(problem_description)) > 0))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inspections DROP CONSTRAINT IF EXISTS inspections_recorded_result_check');
            DB::statement('ALTER TABLE inspections DROP CONSTRAINT IF EXISTS inspections_affected_quantity_check');
            DB::statement('ALTER TABLE inspections DROP CONSTRAINT IF EXISTS inspections_problem_description_check');
        }

        Schema::table('inspections', function (Blueprint $table): void {
            $table->dropColumn(['recorded_result', 'problem_description', 'affected_quantity']);
        });
    }
};
