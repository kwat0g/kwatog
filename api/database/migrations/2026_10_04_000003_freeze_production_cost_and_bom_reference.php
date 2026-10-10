<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->foreignId('bom_id')->nullable()->constrained('bill_of_materials')->restrictOnDelete();
            $table->unsignedInteger('bom_version')->nullable();
            $table->decimal('unit_standard_cost', 15, 4)->nullable();
            $table->string('cost_source', 40)->nullable();
            $table->timestamp('cost_snapshot_at')->nullable();
        });
        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->decimal('receipt_unit_cost', 15, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->dropColumn('receipt_unit_cost');
        });
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bom_id');
            $table->dropColumn(['bom_version', 'unit_standard_cost', 'cost_source', 'cost_snapshot_at']);
        });
    }
};
