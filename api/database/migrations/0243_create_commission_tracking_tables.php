<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained('employees');
            $t->foreignId('product_id')->nullable()->constrained('products');
            $t->decimal('rate', 5, 4);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->timestamps();

            $t->index(['employee_id', 'effective_from']);
        });

        Schema::create('commission_earnings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_order_id')->constrained('sales_orders');
            $t->foreignId('employee_id')->constrained('employees');
            $t->decimal('order_total', 15, 2);
            $t->decimal('commission_rate', 5, 4);
            $t->decimal('commission_amount', 15, 2);
            $t->string('status', 20)->default('pending');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['employee_id', 'status']);
            $t->index(['sales_order_id']);
        });

        Schema::table('sales_orders', function (Blueprint $t) {
            $t->foreignId('sales_rep_id')->nullable()->after('customer_id')->constrained('employees');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sales_rep_id');
        });
        Schema::dropIfExists('commission_earnings');
        Schema::dropIfExists('commission_rates');
    }
};
