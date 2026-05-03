<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 61. Non-Conformance Reports.
 *
 * NCRs are opened by two upstream paths:
 *   - inspection failure (auto-opened by InspectionService::complete)
 *   - customer complaint (Task 68)
 *
 * The disposition decides what happens after closure:
 *   - scrap                → on outgoing QC failure, auto-create replacement WO
 *   - rework               → no automation
 *   - use_as_is            → records concession
 *   - return_to_supplier   → notify Purchasing
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('non_conformance_reports', function (Blueprint $table) {
            $table->id();
            $table->string('ncr_number', 32)->unique();
            $table->string('source', 30);          // inspection_fail | customer_complaint
            $table->string('severity', 10);        // low | medium | high | critical
            $table->string('status', 20)->default('open'); // open | in_progress | closed | cancelled

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('inspection_id')->nullable()->constrained('inspections')->nullOnDelete();
            // Customer complaint FK reserved for Task 68 — column added now to avoid
            // a follow-up migration; constraint omitted until the table exists.
            $table->unsignedBigInteger('complaint_id')->nullable();

            $table->text('defect_description');
            $table->unsignedInteger('affected_quantity')->default(0);
            $table->string('disposition', 30)->nullable(); // scrap | rework | use_as_is | return_to_supplier

            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            // Replacement work order back-link when disposition=scrap on outgoing QC
            $table->foreignId('replacement_work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();

            $table->timestamps();

            $table->index('source');
            $table->index('severity');
            $table->index('status');
            $table->index('disposition');
            $table->index('product_id');
            $table->index('inspection_id');
            $table->index('complaint_id');
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_conformance_reports');
    }
};
