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
        Schema::table('purchase_request_items', function (Blueprint $table): void {
            $table->decimal('quantity', 12, 3)->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->decimal('quantity', 12, 3)->change();
            $table->decimal('quantity_received', 12, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        $fractionalPr = DB::table('purchase_request_items')->whereRaw('quantity <> ROUND(quantity, 2)')->exists();
        $fractionalPo = DB::table('purchase_order_items')
            ->whereRaw('quantity <> ROUND(quantity, 2) OR quantity_received <> ROUND(quantity_received, 2)')
            ->exists();

        if ($fractionalPr || $fractionalPo) {
            throw new RuntimeException('Cannot restore two-decimal purchasing quantities while fractional quantities exist.');
        }

        Schema::table('purchase_request_items', function (Blueprint $table): void {
            $table->decimal('quantity', 12, 2)->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->decimal('quantity', 12, 2)->change();
            $table->decimal('quantity_received', 12, 2)->default(0)->change();
        });
    }
};
