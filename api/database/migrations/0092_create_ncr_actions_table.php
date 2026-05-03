<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 61. NCR action log.
 *
 * Each NCR accumulates a chronological list of actions:
 *   - containment   → immediate measure (quarantine, segregate)
 *   - corrective    → root-cause permanent fix
 *   - preventive    → systemic safeguard against recurrence
 *
 * The 8D report (Task 68) reads these rows to populate D3/D5/D7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ncr_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ncr_id')->constrained('non_conformance_reports')->cascadeOnDelete();
            $table->string('action_type', 20); // containment | corrective | preventive
            $table->text('description');
            $table->foreignId('performed_by')->constrained('users');
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index('ncr_id');
            $table->index('action_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ncr_actions');
    }
};
