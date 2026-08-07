<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope cut (2026-08-07) — drop `budget_transfers`.
 *
 * Reallocating an approved budget between line items is a controller's
 * housekeeping task, not part of ADV9. What the defense demonstrates is the
 * budget itself: allocation per department, spent-vs-allocated with the
 * critical threshold, and enforcement on PR/PO approval. All of that lives in
 * BudgetService / BudgetEnforcementService and is untouched — nothing in the
 * enforcement path ever read budget_transfers.
 *
 * The table was empty in every environment. Its maker-checker rule (a
 * requester may not approve their own transfer) is demonstrated elsewhere and
 * better: payroll compute-vs-approve, salary adjustments, and PR/PO approval
 * chains all keep their SoD tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('budget_transfers');
    }

    public function down(): void
    {
        Schema::create('budget_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_line_item_id')->constrained('budget_line_items')->cascadeOnDelete();
            $table->foreignId('to_line_item_id')->constrained('budget_line_items')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }
};
