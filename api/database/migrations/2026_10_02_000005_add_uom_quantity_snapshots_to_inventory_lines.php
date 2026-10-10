<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grn_items', function (Blueprint $table): void {
            $table->decimal('quantity_received_in_uom', 18, 6)->nullable();
            $table->decimal('received_uom_conversion_factor', 18, 6)->nullable();
        });

        Schema::table('material_issue_slip_items', function (Blueprint $table): void {
            $table->decimal('quantity_issued_in_uom', 18, 6)->nullable();
            $table->decimal('issued_uom_conversion_factor', 18, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('material_issue_slip_items', function (Blueprint $table): void {
            $table->dropColumn(['quantity_issued_in_uom', 'issued_uom_conversion_factor']);
        });

        Schema::table('grn_items', function (Blueprint $table): void {
            $table->dropColumn(['quantity_received_in_uom', 'received_uom_conversion_factor']);
        });
    }
};
