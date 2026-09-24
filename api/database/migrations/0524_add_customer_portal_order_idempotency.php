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
            $table->string('portal_idempotency_key', 128)->nullable();
            $table->string('portal_idempotency_fingerprint', 64)->nullable();
            $table->unique(
                ['customer_id', 'portal_idempotency_key'],
                'sales_orders_customer_portal_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropUnique('sales_orders_customer_portal_idempotency_unique');
            $table->dropColumn(['portal_idempotency_key', 'portal_idempotency_fingerprint']);
        });
    }
};
