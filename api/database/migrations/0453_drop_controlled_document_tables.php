<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope cut — remove the QMS controlled-document module (T3.5).
 *
 * A document-management system bolted onto the ERP: catalogue, revision
 * publishing/diffing, and per-employee read acknowledgments. None of the three
 * chains touch it, and the IATF 16949 story is carried by inspection specs,
 * NCRs and CoCs — not by SOP version control. Dropped in FK dependency order.
 *
 * The unrelated `documents` vault (Common/, used by payslip PDFs) and the HR,
 * SupplyChain and B2B document tables are deliberately untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('document_acknowledgments');
        Schema::dropIfExists('document_revisions');
        Schema::dropIfExists('controlled_documents');
    }

    public function down(): void
    {
        Schema::create('controlled_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_code', 40)->unique();
            $table->string('title');
            $table->string('category', 30);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('review_interval_months')->nullable();
            $table->date('next_review_at')->nullable();
            $table->timestamp('last_review_alert_at')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('assignee_roles')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('controlled_documents')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->text('change_summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'revision_number']);
        });

        Schema::create('document_acknowledgments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_revision_id')->constrained('document_revisions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['document_revision_id', 'user_id']);
        });
    }
};
