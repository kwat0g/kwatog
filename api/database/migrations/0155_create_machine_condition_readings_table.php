<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV8 — Maintenance Automation.
 * Machine condition readings for predictive maintenance.
 * Stores sensor-like readings (temperature, vibration, pressure, current draw)
 * that are analyzed against thresholds to predict failures before they happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_condition_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->restrictOnDelete();
            $table->string('metric', 30);         // temperature | vibration | pressure | current | oil_quality
            $table->decimal('value', 12, 3);
            $table->string('unit', 20);         // celsius | mm/s | bar | amp | percent
            $table->timestamp('recorded_at');
            $table->string('source', 50)->default('manual'); // manual | iot_sensor | plc | api
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['machine_id', 'metric', 'recorded_at']);
            $table->index(['metric', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_condition_readings');
    }
};
