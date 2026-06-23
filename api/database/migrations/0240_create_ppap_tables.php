<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPAP & APQP Tracking (IATF 16949). A PpapSubmission is a supplier's Production
 * Part Approval Process package for a vendor+item, with an element checklist.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ppap_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('ppap_number', 30)->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('ppap_level', 1);
            $table->date('submission_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('submission_document_path', 500)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            // PO-gate lookup: is there an approved, non-expired PPAP for vendor+item?
            $table->index(['vendor_id', 'item_id', 'status'], 'ppap_vendor_item_status_idx');
        });

        Schema::create('ppap_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppap_submission_id')->constrained('ppap_submissions')->cascadeOnDelete();
            $table->string('element_type', 40);
            $table->string('status', 20)->default('pending');
            $table->string('document_path', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['ppap_submission_id', 'element_type'], 'ppap_element_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppap_elements');
        Schema::dropIfExists('ppap_submissions');
    }
};
