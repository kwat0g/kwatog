<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Scope cut (2026-08-07) — drop the performance-review and opening-balance
 * features.
 *
 * Performance reviews (review_cycles / performance_reviews / review_templates):
 * appraisal cycles, self- and manager-assessments, rating scales. Personnel
 * development, not Hire to Retire — nothing in hiring, attendance, leave,
 * payroll or separation read these tables, and their only foreign keys pointed
 * at each other. Training records and the IATF competence matrix are a
 * different feature and are untouched.
 *
 * Opening balances had no table of its own: it wrote a go-live journal entry
 * and an `opening` stock receipt through the normal services. Only the code
 * path is removed (controller, requests, service, SPA page). Rows already
 * posted stay as ordinary journal entries and stock movements, and
 * StockMovementType::Opening survives because MovementGlPostingService still
 * maps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Child first: performance_reviews FKs both other tables.
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('review_cycles');
        Schema::dropIfExists('review_templates');
    }

    public function down(): void
    {
        // Irreversible by design: the models, service, controller, routes, SPA
        // pages and permissions were deleted in the same commit, so restoring
        // the tables alone would leave orphans no code can reach.
        throw new RuntimeException(
            'Irreversible scope cut. Restore the "cut performance reviews" commit '.
            'from git history to reinstate this feature.'
        );
    }
};
