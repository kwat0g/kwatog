<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 66. Fleet vehicle registry.
 *
 * The seeder ships 3 rows: Truck 1, Truck 2, L300 Van. Status reflects
 * current dispatch — `available` is the bookable state for a delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_number', 20)->unique();
            $table->string('name', 100);                         // Display label
            $table->string('vehicle_type', 20);                  // truck | van | motorcycle
            $table->decimal('capacity_kg', 10, 2)->nullable();
            $table->string('status', 20)->default('available'); // available | in_use | maintenance | retired
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
