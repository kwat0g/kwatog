<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_product_access', function (Blueprint $table): void {
            $table->decimal('minimum_order_quantity', 12, 3)->default(1);
            $table->decimal('maximum_order_quantity', 12, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_product_access', function (Blueprint $table): void {
            $table->dropColumn(['minimum_order_quantity', 'maximum_order_quantity']);
        });
    }
};
