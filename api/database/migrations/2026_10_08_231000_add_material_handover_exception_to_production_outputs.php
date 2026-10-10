<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->json('material_handover_exception')->nullable();
        });
        Schema::table('production_logs', function (Blueprint $table): void {
            $table->json('material_handover_exception')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('production_logs', function (Blueprint $table): void {
            $table->dropColumn('material_handover_exception');
        });
        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->dropColumn('material_handover_exception');
        });
    }
};
