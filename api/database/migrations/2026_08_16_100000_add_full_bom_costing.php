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
        Schema::table('bill_of_materials', function (Blueprint $table): void {
            $table->decimal('labor_cost', 15, 2)->nullable()->after('material_cost');
            $table->decimal('machine_cost', 15, 2)->nullable()->after('labor_cost');
            $table->decimal('overhead_cost', 15, 2)->nullable()->after('machine_cost');
            $table->decimal('total_cost', 15, 2)->nullable()->after('overhead_cost');
        });

        Schema::table('bom_items', function (Blueprint $table): void {
            $table->string('cost_source', 30)->nullable()->after('extended_cost');
        });

        Schema::table('routing_operations', function (Blueprint $table): void {
            $table->decimal('labor_rate_per_hour', 15, 4)->default(0)->after('cycle_time_minutes');
            $table->decimal('machine_rate_per_hour', 15, 4)->default(0)->after('labor_rate_per_hour');
            $table->decimal('overhead_rate_per_hour', 15, 4)->default(0)->after('machine_rate_per_hour');
        });

        // Preserve the previous material-only snapshots for existing BOMs;
        // a later explicit recalculate will add any routing costs available.
        DB::table('bill_of_materials')
            ->whereNull('labor_cost')
            ->update([
                'labor_cost' => 0,
                'machine_cost' => 0,
                'overhead_cost' => 0,
                'total_cost' => DB::raw('COALESCE(material_cost, 0)'),
            ]);
        DB::table('bom_items')
            ->whereNull('cost_source')
            ->whereNotNull('unit_cost')
            ->update(['cost_source' => 'standard_cost']);
    }

    public function down(): void
    {
        Schema::table('routing_operations', function (Blueprint $table): void {
            $table->dropColumn(['labor_rate_per_hour', 'machine_rate_per_hour', 'overhead_rate_per_hour']);
        });

        Schema::table('bom_items', function (Blueprint $table): void {
            $table->dropColumn('cost_source');
        });

        Schema::table('bill_of_materials', function (Blueprint $table): void {
            $table->dropColumn(['labor_cost', 'machine_cost', 'overhead_cost', 'total_cost']);
        });
    }
};
