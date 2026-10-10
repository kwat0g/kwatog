<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spare_part_usage', function (Blueprint $table): void {
            $table->foreignId('work_log_id')->nullable()->constrained('maintenance_logs')->nullOnDelete();
            $table->index('work_log_id');
        });
    }

    public function down(): void
    {
        Schema::table('spare_part_usage', function (Blueprint $table): void {
            $table->dropForeign(['work_log_id']);
            $table->dropIndex(['work_log_id']);
            $table->dropColumn('work_log_id');
        });
    }
};
