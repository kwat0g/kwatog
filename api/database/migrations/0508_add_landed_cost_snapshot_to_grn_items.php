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
            $table->decimal('landed_cost_unit', 15, 4)->nullable()->after('unit_cost');
            $table->decimal('landed_cost_total', 15, 2)->nullable()->after('landed_cost_unit');
        });
    }

    public function down(): void
    {
        Schema::table('grn_items', function (Blueprint $table): void {
            $table->dropColumn(['landed_cost_unit', 'landed_cost_total']);
        });
    }
};
