<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * How many days after a cutoff ends its payroll date may still fall.
 *
 * payroll_date selects the effective-dated government contribution tables, the
 * de minimis month, and the GL posting date — so a date in the wrong month or
 * year silently withholds and remits the wrong amounts (see
 * PayrollPeriodService::assertPayrollDateIsPlausible). 45 days is generous
 * enough for a delayed run or a cutoff paid the following month, while making a
 * wrong year impossible.
 */
return new class extends Migration
{
    private const KEY = 'payroll.payroll_date.max_days_after_period_end';

    public function up(): void
    {
        if (DB::table('settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key'         => self::KEY,
            'value'       => 45,
            'group'       => 'payroll',
            'label'       => 'Payroll Date Grace Days',
            'description' => 'Maximum days after a period ends that its payroll date may fall. Guards against a date in the wrong month or year selecting the wrong government contribution tables.',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
