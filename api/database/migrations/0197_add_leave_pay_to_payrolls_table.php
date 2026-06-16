<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-003 — Pay daily-rated employees for approved paid leave.
 *
 * Daily-rated workers were paid ₱0 for approved paid-leave days because the
 * leave service zeroes the attendance hours and payroll only paid for
 * days_worked. We add a dedicated leave_pay earnings line so the amount is
 * transparent on the payslip and traceable in the GL (folded into Salaries
 * Expense), rather than silently inflating basic_pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('leave_pay', 15, 2)->default(0)->after('holiday_pay');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn('leave_pay');
        });
    }
};
