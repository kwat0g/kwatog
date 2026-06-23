<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mold Lifecycle Manager — track a mold as a first-class production asset:
 * commission/decommission dates, cumulative maintenance cost + count,
 * acquisition + replacement cost for amortization, and a preventive-maintenance
 * shot interval.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('molds', function (Blueprint $table) {
            $table->date('commissioned_at')->nullable()->after('status');
            $table->date('decommissioned_at')->nullable()->after('commissioned_at');
            $table->date('last_maintenance_at')->nullable()->after('decommissioned_at');
            $table->unsignedInteger('maintenance_count')->default(0)->after('last_maintenance_at');
            $table->decimal('total_maintenance_cost', 15, 2)->default(0)->after('maintenance_count');
            $table->decimal('acquisition_cost', 15, 2)->default(0)->after('total_maintenance_cost');
            $table->decimal('estimated_replacement_cost', 15, 2)->default(0)->after('acquisition_cost');
            $table->unsignedInteger('maintenance_frequency_shots')->nullable()->after('estimated_replacement_cost');
            $table->string('drawing_number', 50)->nullable()->after('maintenance_frequency_shots');
            $table->string('storage_location', 100)->nullable()->after('drawing_number');
        });
    }

    public function down(): void
    {
        Schema::table('molds', function (Blueprint $table) {
            $table->dropColumn([
                'commissioned_at', 'decommissioned_at', 'last_maintenance_at',
                'maintenance_count', 'total_maintenance_cost', 'acquisition_cost',
                'estimated_replacement_cost', 'maintenance_frequency_shots',
                'drawing_number', 'storage_location',
            ]);
        });
    }
};
