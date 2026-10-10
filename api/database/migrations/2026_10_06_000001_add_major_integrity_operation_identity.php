<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_note_applications', function (Blueprint $table): void {
            $table->uuid('operation_key')->nullable()->unique();
        });
        Schema::table('spare_part_usage', function (Blueprint $table): void {
            $table->uuid('operation_key')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users');
        });
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->string('pre_maintenance_status', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_work_orders', fn (Blueprint $table) => $table->dropColumn('pre_maintenance_status'));
        Schema::table('spare_part_usage', function (Blueprint $table): void {
            $table->dropUnique(['operation_key']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('operation_key');
        });
        Schema::table('credit_note_applications', function (Blueprint $table): void {
            $table->dropUnique(['operation_key']);
            $table->dropColumn('operation_key');
        });
    }
};
