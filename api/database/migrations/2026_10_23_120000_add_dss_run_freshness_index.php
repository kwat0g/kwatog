<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dss_run_logs', function (Blueprint $table): void {
            $table->index(['detector_id', 'started_at'], 'dss_run_logs_detector_started_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dss_run_logs', function (Blueprint $table): void {
            $table->dropIndex('dss_run_logs_detector_started_idx');
        });
    }
};
