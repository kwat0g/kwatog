<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_downtimes', function (Blueprint $table): void {
            $table->foreign('maintenance_order_id', 'machine_downtimes_maintenance_order_id_foreign')
                ->references('id')
                ->on('maintenance_work_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machine_downtimes', function (Blueprint $table): void {
            $table->dropForeign('machine_downtimes_maintenance_order_id_foreign');
        });
    }
};
