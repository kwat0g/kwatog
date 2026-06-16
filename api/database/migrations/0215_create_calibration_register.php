<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-016 — IATF 16949 calibration register.
 *
 * Measuring/monitoring equipment (gauges, calipers, CMMs) must be calibrated on
 * a schedule; an IATF assessor expects a register showing last/next calibration
 * and due/overdue status. Mirrors the training-expiry pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calibration_records', function (Blueprint $table) {
            $table->id();
            $table->string('equipment_code', 50)->unique();
            $table->string('name', 150);
            $table->string('location', 100)->nullable();
            $table->date('last_calibration_date')->nullable();
            $table->date('next_calibration_date')->nullable();
            $table->unsignedSmallInteger('frequency_days')->default(365);
            $table->string('status', 20)->default('active'); // active|due|overdue|retired
            $table->string('responsible', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('next_calibration_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calibration_records');
    }
};
