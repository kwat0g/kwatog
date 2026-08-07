<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope cut (2026-08-07) — drop `budget_revisions`.
 *
 * L-26 shipped a revision request/approve pair that never applied anything.
 * `BudgetController::approveRevision()` stamped `status` + `approved_by` and
 * left the change set unapplied — its own docblock deferred that to
 * "whichever downstream service knows the field semantics", and no such
 * service was ever written. So an approved revision changed no budget figure.
 *
 * There was no SPA screen, no test, no dedicated permission (it borrowed
 * `budgeting.view/manage/approve`) and the table was empty in every
 * environment. Budget approval, the ADV9 commitment ledger and PR/PO
 * enforcement are untouched — those are the parts that hold a defense.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('budget_revisions');
    }

    public function down(): void
    {
        Schema::create('budget_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->integer('revision_number');
            $table->json('changes');
            $table->text('reason');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });
    }
};
