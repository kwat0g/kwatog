<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamp('in_production_at')->nullable()->index();
            $table->timestamp('partially_delivered_at')->nullable()->index();
            $table->timestamp('delivered_at')->nullable()->index();
            $table->timestamp('invoiced_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'confirmed_at',
                'in_production_at',
                'partially_delivered_at',
                'delivered_at',
                'invoiced_at',
                'cancelled_at',
            ]);
        });
    }
};
