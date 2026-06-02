<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_locations', function (Blueprint $table): void {
            $table->decimal('capacity_kg', 10, 2)->nullable()->after('bin');
            $table->foreignId('current_item_id')->nullable()->constrained('items')->nullOnDelete()->after('capacity_kg');
            $table->decimal('current_quantity', 15, 3)->default(0)->after('current_item_id');
            $table->string('current_lot_number', 50)->nullable()->after('current_quantity');
            $table->boolean('is_blocked')->default(false)->after('current_lot_number');
            $table->string('blocked_reason', 200)->nullable()->after('is_blocked');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_locations', function (Blueprint $table): void {
            $table->dropColumn(['capacity_kg', 'current_item_id', 'current_quantity', 'current_lot_number', 'is_blocked', 'blocked_reason']);
        });
    }
};
