<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U4 — onboarding workflow tracker. One row per employee.
 * Each timestamp marks step completion. `completed_at` set when all steps done.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_onboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()
                ->constrained('employees')->cascadeOnDelete();

            $table->timestamp('profile_completed_at')->nullable();
            $table->timestamp('shift_assigned_at')->nullable();
            $table->timestamp('leave_balances_initialized_at')->nullable();
            $table->timestamp('account_provisioned_at')->nullable();
            $table->timestamp('dept_team_notified_at')->nullable();
            $table->timestamp('gov_ids_recorded_at')->nullable();
            $table->timestamp('banking_recorded_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_onboardings');
    }
};
