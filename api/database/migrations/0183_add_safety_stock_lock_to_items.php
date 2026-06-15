<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('safety_stock_locked')->default(false)->after('safety_stock');
            $table->timestamp('safety_stock_recomputed_at')->nullable()->after('safety_stock_locked');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['safety_stock_locked', 'safety_stock_recomputed_at']);
        });
    }
};
