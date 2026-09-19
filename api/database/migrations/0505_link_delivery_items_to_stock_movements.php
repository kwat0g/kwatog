<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_items', function (Blueprint $table): void {
            $table->foreignId('stock_movement_id')
                ->nullable()
                ->after('inspection_id')
                ->constrained('stock_movements')
                ->restrictOnDelete();
            $table->unique('stock_movement_id', 'delivery_items_stock_movement_unique');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_items', function (Blueprint $table): void {
            $table->dropUnique('delivery_items_stock_movement_unique');
            $table->dropConstrainedForeignId('stock_movement_id');
        });
    }
};
