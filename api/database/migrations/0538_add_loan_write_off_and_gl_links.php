<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_loans', function (Blueprint $table): void {
            $table->decimal('write_off_amount', 15, 2)->nullable();
            $table->text('write_off_reason')->nullable();
            $table->text('write_off_evidence')->nullable();
            $table->text('write_off_approval_remarks')->nullable();
            $table->foreignId('write_off_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('write_off_requested_at')->nullable();
            $table->foreignId('write_off_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('write_off_approved_at')->nullable();
            $table->foreignId('write_off_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('disbursement_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->index('write_off_requested_by');
            $table->index('write_off_approved_by');
        });

        Schema::table('loan_payments', function (Blueprint $table): void {
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loan_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::table('employee_loans', function (Blueprint $table): void {
            $table->dropForeign(['write_off_requested_by']);
            $table->dropForeign(['write_off_approved_by']);
            $table->dropForeign(['write_off_journal_entry_id']);
            $table->dropForeign(['disbursement_journal_entry_id']);
            $table->dropIndex(['write_off_requested_by']);
            $table->dropIndex(['write_off_approved_by']);
            $table->dropColumn([
                'write_off_amount',
                'write_off_reason',
                'write_off_evidence',
                'write_off_approval_remarks',
                'write_off_requested_by',
                'write_off_requested_at',
                'write_off_approved_by',
                'write_off_approved_at',
                'write_off_journal_entry_id',
                'disbursement_journal_entry_id',
            ]);
        });
    }
};
