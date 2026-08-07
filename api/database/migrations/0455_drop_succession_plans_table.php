<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Scope cut — remove HR succession planning.
 *
 * Bench-strength tracking (who could backfill which position, and how ready
 * they are) is a strategic HR practice, not part of Chain 3 (Hire to Retire).
 * Nothing in hiring, attendance, payroll, or separation reads it, and no table
 * references `succession_plans`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('succession_plans');
    }

    public function down(): void
    {
        // Irreversible by design: the models, service, controller, routes, SPA
        // pages and permissions were deleted in the same commit, so restoring
        // the table alone would leave an orphan no code can reach.
        throw new RuntimeException(
            'Irreversible scope cut. Restore the "cut HR succession planning" '.
            'commit from git history to reinstate this feature.'
        );
    }
};
