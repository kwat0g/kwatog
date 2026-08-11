<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->string('payslip_email_status', 20)
                ->default('pending')
                ->after('payslip_emailed_at');
            $table->unsignedSmallInteger('payslip_email_attempts')
                ->default(0)
                ->after('payslip_email_status');
            $table->timestamp('payslip_email_queued_at')
                ->nullable()
                ->after('payslip_email_attempts');
            $table->text('payslip_email_last_error')
                ->nullable()
                ->after('payslip_email_queued_at');
            $table->index(
                ['payslip_email_status', 'payslip_email_queued_at'],
                'payrolls_payslip_email_state_index',
            );
        });

        // The legacy timestamp was the only delivery marker. Preserve rows
        // already marked there as sent; new rows begin in an explicit pending
        // state and are only marked sent after the delivery job succeeds.
        DB::table('payrolls')
            ->whereNotNull('payslip_emailed_at')
            ->update(['payslip_email_status' => 'sent']);
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table): void {
            $table->dropIndex('payrolls_payslip_email_state_index');
            $table->dropColumn([
                'payslip_email_status',
                'payslip_email_attempts',
                'payslip_email_queued_at',
                'payslip_email_last_error',
            ]);
        });
    }
};
