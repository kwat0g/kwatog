<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoice_payment_submissions DROP CONSTRAINT invoice_payment_amount');
        DB::statement('ALTER TABLE invoice_payment_submissions ADD CONSTRAINT invoice_payment_amount CHECK (amount > 0 AND amount <= balance_at_submission)');
    }

    public function down(): void
    {
        if (DB::table('invoice_payment_submissions')->whereColumn('amount', '<>', 'balance_at_submission')->exists()) {
            throw new RuntimeException('Partial payment evidence exists. Preserve it and use a compatibility rollback.');
        }

        DB::statement('ALTER TABLE invoice_payment_submissions DROP CONSTRAINT invoice_payment_amount');
        DB::statement('ALTER TABLE invoice_payment_submissions ADD CONSTRAINT invoice_payment_amount CHECK (amount > 0 AND amount = balance_at_submission)');
    }
};
