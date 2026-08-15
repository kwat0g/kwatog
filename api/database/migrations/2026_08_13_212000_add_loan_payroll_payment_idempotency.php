<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        $duplicates = DB::table('loan_payments')
            ->select('loan_id', 'payroll_id', 'payment_type', DB::raw('count(*) as copies'))
            ->whereNotNull('payroll_id')
            ->where('payment_type', 'payroll_deduction')
            ->groupBy('loan_id', 'payroll_id', 'payment_type')
            ->havingRaw('count(*) > 1')
            ->limit(1)
            ->first();
        if ($duplicates) {
            throw new RuntimeException('Cannot add payroll loan-payment idempotency guard: duplicate ledger rows exist.');
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX loan_payments_payroll_deduction_unique
            ON loan_payments (loan_id, payroll_id)
            WHERE payroll_id IS NOT NULL AND payment_type = 'payroll_deduction'
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS loan_payments_payroll_deduction_unique');
        }
    }
};
