<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->foreignId('quarantine_location_id')->nullable()->after('serial_number')->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('quarantine_movement_id')->nullable()->after('quarantine_location_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('quarantine_release_movement_id')->nullable()->after('quarantine_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->string('quarantine_status', 20)->nullable()->after('quarantine_release_movement_id');
        });
    }

    public function down(): void
    {
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->dropForeign(['quarantine_location_id']);
            $table->dropForeign(['quarantine_movement_id']);
            $table->dropForeign(['quarantine_release_movement_id']);
            $table->dropColumn(['quarantine_location_id', 'quarantine_movement_id', 'quarantine_release_movement_id', 'quarantine_status']);
        });
    }
};
