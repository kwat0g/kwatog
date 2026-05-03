<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 7 — Task 68. Customer complaints + 8D-style root-cause workflow.
 *
 * On complaint creation an NCR is auto-opened (Task 61 NcrService takes
 * source=customer_complaint and links complaint_id back to this row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_complaints', function (Blueprint $table) {
            $table->id();
            $table->string('complaint_number', 32)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->date('received_date');
            $table->string('severity', 10);              // low | medium | high | critical
            $table->string('status', 20)->default('open'); // open | investigating | resolved | closed | cancelled
            $table->text('description');                 // What the customer reported
            $table->unsignedInteger('affected_quantity')->default(0);
            $table->foreignId('ncr_id')->nullable()->constrained('non_conformance_reports')->nullOnDelete();
            $table->foreignId('replacement_work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('credit_memo_id')->nullable(); // FK reserved for finance Sprint 8
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('severity');
            $table->index('customer_id');
            $table->index('product_id');
            $table->index('received_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_complaints');
    }
};
