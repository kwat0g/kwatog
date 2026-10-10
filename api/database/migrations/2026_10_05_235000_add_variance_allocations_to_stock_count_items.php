<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->json('variance_allocations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->dropColumn('variance_allocations');
        });
    }
};
