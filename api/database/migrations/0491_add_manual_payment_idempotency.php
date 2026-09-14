<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('loan_payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
        });
        Schema::table('bill_payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX CONCURRENTLY loan_payments_loan_idempotency_unique
                   ON loan_payments (loan_id, idempotency_key)
                 WHERE idempotency_key IS NOT NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX CONCURRENTLY bill_payments_bill_idempotency_unique
                   ON bill_payments (bill_id, idempotency_key)
                 WHERE idempotency_key IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS loan_payments_loan_idempotency_unique');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS bill_payments_bill_idempotency_unique');
        }

        Schema::table('loan_payments', function (Blueprint $table): void {
            $table->dropColumn('idempotency_key');
        });
        Schema::table('bill_payments', function (Blueprint $table): void {
            $table->dropColumn('idempotency_key');
        });
    }
};
