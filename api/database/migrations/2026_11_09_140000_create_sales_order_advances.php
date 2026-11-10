<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->timestampTz('mrp_released_at')->nullable();
        });

        Schema::create('sales_order_advances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->decimal('order_total_snapshot', 15, 2);
            $table->decimal('required_amount', 15, 2);
            $table->decimal('percentage', 5, 2)->nullable();
            $table->decimal('submitted_amount', 15, 2)->nullable();
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->string('status', 24)->default('configured');
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('customer_portal_users')->nullOnDelete();
            $table->string('sending_bank', 120)->nullable();
            $table->string('reference_number', 120)->nullable();
            $table->string('reference_normalized', 120)->nullable();
            $table->date('payment_date')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_file_name')->nullable();
            $table->string('proof_mime', 120)->nullable();
            $table->unsignedBigInteger('proof_size')->nullable();
            $table->char('proof_sha256', 64)->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->char('request_fingerprint', 64)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('refund_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('refund_reference', 120)->nullable();
            $table->text('refund_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['sales_order_id', 'attempt_number'], 'sales_order_advance_attempt_unique');
            $table->unique(['customer_id', 'reference_normalized'], 'customer_advance_reference_unique');
            $table->unique(['customer_id', 'refund_reference'], 'customer_advance_refund_reference_unique');
            $table->index(['sales_order_id', 'attempt_number', 'status'], 'sales_order_advance_latest_index');
            $table->index(['status', 'created_at'], 'sales_order_advance_review_index');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_advances');
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn('mrp_released_at');
        });
    }
};
