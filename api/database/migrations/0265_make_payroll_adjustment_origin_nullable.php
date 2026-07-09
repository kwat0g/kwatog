<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REC-10 — allow payroll adjustments with no originating payroll.
 *
 * The table was built to CORRECT a prior payroll (underpayment/overpayment
 * recovery), so payroll_period_id + original_payroll_id were required FKs. A
 * year-end leave encashment is a fresh earning with no originating payroll —
 * it is an approved adjustment picked up on the employee's NEXT run
 * (applyApprovedAdjustments queries by employee + approved + unapplied, not by
 * period). Make both columns nullable so encashment adjustments are valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: drop the NOT NULL constraint. The FKs stay (nullable FKs
        // are fine). We must drop + re-add the FK to change nullability cleanly
        // on some drivers, but Postgres supports ALTER COLUMN DROP NOT NULL
        // directly, which Laravel's change() emits.
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->foreignId('payroll_period_id')->nullable()->change();
            $table->foreignId('original_payroll_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->foreignId('payroll_period_id')->nullable(false)->change();
            $table->foreignId('original_payroll_id')->nullable(false)->change();
        });
    }
};
