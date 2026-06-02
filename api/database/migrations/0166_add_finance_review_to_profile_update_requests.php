<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task SS2 — bank-account changes need TWO approvals: HR Officer AND Finance
 * Officer (a bank change affects payroll disbursement). We track the Finance
 * leg separately so a request can be HR-approved while still awaiting Finance.
 *
 * Status lifecycle for a bank request:
 *   pending → pending_finance (HR approved, awaiting Finance) → approved
 *   any stage → rejected
 *
 * Non-financial requests keep the original single-approval lifecycle
 * (pending → approved/rejected) and ignore these columns.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('profile_update_requests', function (Blueprint $table) {
            $table->boolean('requires_finance')->default(false)->after('status');
            $table->foreignId('finance_reviewed_by')->nullable()->after('review_remarks')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('finance_reviewed_at')->nullable()->after('finance_reviewed_by');
            $table->text('finance_remarks')->nullable()->after('finance_reviewed_at');

            $table->index('requires_finance');
        });
    }

    public function down(): void
    {
        Schema::table('profile_update_requests', function (Blueprint $table) {
            $table->dropColumn([
                'requires_finance',
                'finance_reviewed_by',
                'finance_reviewed_at',
                'finance_remarks',
            ]);
        });
    }
};
