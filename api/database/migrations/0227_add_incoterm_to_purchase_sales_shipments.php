<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('incoterm', 3)->nullable()->after('remarks');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('incoterm', 3)->nullable()->after('delivery_terms');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('incoterm', 3)->nullable()->after('bl_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('incoterm');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('incoterm');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('incoterm');
        });
    }
};
