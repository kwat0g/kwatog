<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->date('requested_delivery_date')->nullable();
        });
        Schema::table('sales_order_responses', function (Blueprint $table): void {
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_responses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proposed_by');
        });
        Schema::table('sales_order_items', function (Blueprint $table): void {
            $table->dropColumn('requested_delivery_date');
        });
    }
};
