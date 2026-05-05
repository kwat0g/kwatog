<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task A5 — Preventive maintenance auto-scheduling on machine running hours.
 * Adds a running tally that can be used as the threshold input.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->decimal('running_hours_total', 10, 2)->default(0)->after('current_work_order_id');
            $table->timestamp('running_hours_updated_at')->nullable()->after('running_hours_total');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['running_hours_total', 'running_hours_updated_at']);
        });
    }
};
