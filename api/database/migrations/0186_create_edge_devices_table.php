<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 100)->unique();
            $table->string('name', 100);
            $table->string('device_type', 30); // see EdgeDeviceType enum
            $table->string('location', 100)->nullable(); // machine_code, work_cell, etc.
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['device_type', 'is_active']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_devices');
    }
};
