<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope cut (2026-08-07) — drop the SPC control-chart tables.
 *
 * X̄-R charts, subgroup data points and Nelson run-rule alerts were a second,
 * parallel detector for a signal the IATF inspection path already raises:
 * InspectionService::recordMeasurements() evaluates every measurement against
 * its tolerance and opens an NCR on failure, and the defect Pareto ranks what
 * actually failed. The charts never reached the 20-point minimum their own
 * policy required to compute limits — the tables held 1 chart, 0 data points
 * and 0 alerts.
 *
 * Process capability (Cp / Cpk) is NOT part of this cut. It reads
 * inspection_measurements directly, needs no chart row, and still backs the
 * Cp/Cpk panel on the inspection-spec editor and the capability study page.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Child-first: alerts and data points both reference the chart.
        Schema::dropIfExists('spc_alerts');
        Schema::dropIfExists('spc_data_points');
        Schema::dropIfExists('spc_control_charts');
    }

    public function down(): void
    {
        Schema::create('spc_control_charts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('spec_item_id')->constrained('inspection_spec_items')->cascadeOnDelete();
            $table->string('chart_type', 20);
            $table->unsignedSmallInteger('subgroup_size')->default(5);
            $table->decimal('center_line', 15, 6)->nullable();
            $table->decimal('ucl', 15, 6)->nullable();
            $table->decimal('lcl', 15, 6)->nullable();
            $table->decimal('r_bar', 15, 6)->nullable();
            $table->decimal('r_ucl', 15, 6)->nullable();
            $table->decimal('r_lcl', 15, 6)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('limits_calculated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('spc_data_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('control_chart_id')->constrained('spc_control_charts')->cascadeOnDelete();
            $table->unsignedInteger('subgroup_number');
            $table->json('measurements');
            $table->decimal('mean', 15, 6);
            $table->decimal('range', 15, 6);
            $table->json('inspection_ids')->nullable();
            $table->boolean('is_out_of_control')->default(false);
            $table->timestamps();
        });

        Schema::create('spc_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('control_chart_id')->constrained('spc_control_charts')->cascadeOnDelete();
            $table->foreignId('data_point_id')->nullable()->constrained('spc_data_points')->nullOnDelete();
            $table->string('rule_code', 30);
            $table->text('description')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('acknowledgement_notes')->nullable();
            $table->timestamps();
        });
    }
};
