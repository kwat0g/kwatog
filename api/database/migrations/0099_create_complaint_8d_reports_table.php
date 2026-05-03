<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 68. 8D root-cause analysis report.
 *
 * One row per complaint. The 8 disciplines are stored as discrete columns
 * so the PDF template can render them as labelled sections without joining
 * a child table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_8d_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained('customer_complaints')->cascadeOnDelete();
            $table->text('d1_team')->nullable();                  // Establish the team
            $table->text('d2_problem')->nullable();               // Describe the problem
            $table->text('d3_containment')->nullable();           // Interim containment actions
            $table->text('d4_root_cause')->nullable();            // Define and verify root cause
            $table->text('d5_corrective_action')->nullable();     // Choose + verify permanent corrective action
            $table->text('d6_verification')->nullable();          // Implement + validate corrective action
            $table->text('d7_prevention')->nullable();            // Prevent recurrence (systemic safeguards)
            $table->text('d8_recognition')->nullable();           // Recognise team contributions
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('complaint_id'); // 1-to-1 with complaint
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_8d_reports');
    }
};
