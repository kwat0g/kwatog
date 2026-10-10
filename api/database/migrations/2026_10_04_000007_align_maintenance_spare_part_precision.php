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
        Schema::table('spare_part_usage', function (Blueprint $table): void {
            $table->decimal('quantity', 15, 3)->change();
            $table->decimal('unit_cost', 15, 4)->change();
        });

        // Recover precision from the immutable stock ledger where it exists.
        DB::statement('UPDATE spare_part_usage AS usage
            SET quantity = movement.quantity, unit_cost = movement.unit_cost
            FROM stock_movements AS movement
            WHERE usage.stock_movement_id = movement.id');
    }

    public function down(): void
    {
        if (DB::table('spare_part_usage')->whereRaw('quantity <> ROUND(quantity, 2)
            OR quantity > 99999999.99 OR unit_cost <> ROUND(unit_cost, 2)')->exists()) {
            throw new RuntimeException('Cannot shrink spare-part precision without rounding existing ledger values.');
        }

        Schema::table('spare_part_usage', function (Blueprint $table): void {
            $table->decimal('quantity', 10, 2)->change();
            $table->decimal('unit_cost', 15, 2)->change();
        });
    }
};
