<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table): void {
            $table->dropForeign('customer_complaints_customer_id_foreign');
            $table->foreign('customer_id', 'customer_complaints_customer_id_foreign')
                ->references('id')
                ->on('customers')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table): void {
            $table->dropForeign('customer_complaints_customer_id_foreign');
            $table->foreign('customer_id', 'customer_complaints_customer_id_foreign')
                ->references('id')
                ->on('customers')
                ->cascadeOnDelete();
        });
    }
};
