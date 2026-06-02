<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Payroll periods: disbursement lifecycle ──────────────────────
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->string('disbursement_status', 20)->default('pending')->after('status');
            // Values: pending, partially_disbursed, disbursed
            $table->timestamp('disbursed_at')->nullable()->after('disbursement_status');
            $table->foreignId('disbursed_by')->nullable()->after('disbursed_at')
                ->constrained('users')->nullOnDelete();
        });

        // ── Disbursement proof files ────────────────────────────────────
        Schema::create('payroll_disbursement_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained()->cascadeOnDelete();
            $table->string('proof_type', 30);  // deposit_slip, bank_confirmation, transfer_receipt, other
            $table->string('file_name', 200);
            $table->string('file_path', 500);
            $table->string('bank_name', 100)->nullable();
            $table->string('transaction_reference', 100)->nullable();
            $table->decimal('disbursed_amount', 15, 2)->nullable();
            $table->date('disbursement_date');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('payroll_period_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_disbursement_proofs');

        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropForeign(['disbursed_by']);
            $table->dropColumn(['disbursement_status', 'disbursed_at', 'disbursed_by']);
        });
    }
};
