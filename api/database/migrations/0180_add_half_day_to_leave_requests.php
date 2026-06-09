<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-18 / Phase 5b — Half-day leave support.
 *
 * leave_requests.half_day_period: nullable enum 'am'|'pm'.
 *  - NULL  → full-day request (today's default behavior).
 *  - 'am'  → half-day in the morning.
 *  - 'pm'  → half-day in the afternoon.
 *
 * Overlap detection (see LeaveRequestService::submit) treats two requests
 * on the same date as colliding ONLY when one is full-day or when their
 * half_day_period matches. An AM and a PM request on the same day do not
 * collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_requests')) {
            return;
        }
        if (! Schema::hasColumn('leave_requests', 'half_day_period')) {
            Schema::table('leave_requests', function (Blueprint $t) {
                $t->string('half_day_period', 2)->nullable()->after('days');
                $t->index(['employee_id', 'start_date', 'half_day_period'], 'leave_requests_emp_date_half_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_requests') || ! Schema::hasColumn('leave_requests', 'half_day_period')) {
            return;
        }
        Schema::table('leave_requests', function (Blueprint $t) {
            $t->dropIndex('leave_requests_emp_date_half_idx');
            $t->dropColumn('half_day_period');
        });
    }
};
