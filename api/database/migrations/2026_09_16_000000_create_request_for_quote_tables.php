<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_for_quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('rfq_number', 20)->unique();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            $table->string('title', 200);
            $table->text('instructions')->nullable();
            $table->char('currency', 3)->default('PHP');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('closes_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('evaluation_started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('no_award_reason')->nullable();
            $table->string('budget_warning_level', 30)->nullable();
            $table->text('budget_warning_message')->nullable();
            $table->foreignId('budget_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('budget_acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'closes_at']);
            $table->index('purchase_request_id');
        });

        Schema::create('request_for_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_for_quote_id')->constrained('request_for_quotes')->cascadeOnDelete();
            $table->foreignId('purchase_request_item_id')->constrained('purchase_request_items')->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('description', 255);
            $table->text('specification')->nullable();
            $table->decimal('quantity', 15, 4);
            $table->string('unit', 30)->nullable();
            $table->date('required_delivery_date')->nullable();
            $table->boolean('allow_partial_quantity')->default(true);
            $table->boolean('allow_substitute')->default(false);
            $table->timestamps();
            $table->unique(['request_for_quote_id', 'purchase_request_item_id'], 'rfq_items_source_unique');
        });

        Schema::create('request_for_quote_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_for_quote_id')->constrained('request_for_quotes')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('invited_at');
            $table->timestamp('viewed_at')->nullable();
            $table->string('status', 30)->default('invited');
            $table->text('exception_reason')->nullable();
            $table->timestamp('portal_notified_at')->nullable();
            $table->timestamp('email_notified_at')->nullable();
            $table->text('last_notification_error')->nullable();
            $table->timestamps();
            $table->unique(['request_for_quote_id', 'vendor_id'], 'rfq_invitation_vendor_unique');
            $table->index(['vendor_id', 'status']);
        });

        Schema::create('supplier_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_for_quote_id')->constrained('request_for_quotes')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('invitation_id')->constrained('request_for_quote_invitations')->cascadeOnDelete();
            $table->foreignId('portal_user_id')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 30)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->boolean('is_current')->default(true);
            $table->boolean('vat_inclusive')->default(false);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('freight_amount', 15, 2)->default(0);
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->decimal('total_delivered_cost', 15, 2)->default(0);
            $table->date('quote_valid_until')->nullable();
            $table->string('payment_terms', 150)->nullable();
            $table->text('notes')->nullable();
            $table->string('quotation_path', 500)->nullable();
            $table->string('quotation_original_filename', 255)->nullable();
            $table->timestamps();
            $table->unique(['request_for_quote_id', 'vendor_id', 'version'], 'supplier_quote_version_unique');
            $table->index(['request_for_quote_id', 'vendor_id', 'is_current']);
        });

        Schema::create('supplier_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_quote_id')->constrained('supplier_quotes')->cascadeOnDelete();
            $table->foreignId('request_for_quote_item_id')->constrained('request_for_quote_items')->cascadeOnDelete();
            $table->string('response_status', 20)->default('quoted');
            $table->decimal('offered_quantity', 15, 4)->nullable();
            $table->decimal('unit_price', 15, 4)->nullable();
            $table->decimal('line_vat_amount', 15, 2)->default(0);
            $table->decimal('line_freight_amount', 15, 2)->default(0);
            $table->decimal('line_other_charges', 15, 2)->default(0);
            $table->decimal('line_total_delivered_cost', 15, 2)->default(0);
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->date('proposed_delivery_date')->nullable();
            $table->decimal('minimum_order_quantity', 15, 4)->nullable();
            $table->decimal('order_quantity_multiple', 15, 4)->nullable();
            $table->string('compliance_status', 20)->default('pending');
            $table->text('compliance_notes')->nullable();
            $table->timestamps();
            $table->unique(['supplier_quote_id', 'request_for_quote_item_id'], 'supplier_quote_item_unique');
        });

        Schema::create('rfq_awards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_for_quote_id')->constrained('request_for_quotes')->cascadeOnDelete();
            $table->foreignId('request_for_quote_item_id')->constrained('request_for_quote_items')->restrictOnDelete();
            $table->foreignId('supplier_quote_id')->constrained('supplier_quotes')->restrictOnDelete();
            $table->foreignId('supplier_quote_item_id')->constrained('supplier_quote_items')->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->decimal('awarded_quantity', 15, 4);
            $table->decimal('awarded_unit_price', 15, 4);
            $table->decimal('awarded_total_delivered_cost', 15, 2);
            $table->text('award_reason');
            $table->text('single_response_justification')->nullable();
            $table->string('status', 20)->default('awarded');
            $table->foreignId('awarded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('awarded_at');
            $table->timestamps();
            $table->index(['request_for_quote_item_id', 'status']);
        });

        Schema::create('rfq_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_for_quote_id')->constrained('request_for_quotes')->cascadeOnDelete();
            $table->foreignId('supplier_quote_id')->nullable()->constrained('supplier_quotes')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('uploaded_by_user')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('uploaded_by_portal_user')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->string('document_type', 40);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('file_path', 500);
            $table->timestamps();
            $table->index(['request_for_quote_id', 'vendor_id']);
        });

        Schema::create('rfq_addenda', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_for_quote_id')->constrained('request_for_quotes')->cascadeOnDelete();
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('title', 200);
            $table->text('body');
            $table->boolean('material_change')->default(false);
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['request_for_quote_id', 'sequence'], 'rfq_addenda_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfq_addenda');
        Schema::dropIfExists('rfq_documents');
        Schema::dropIfExists('rfq_awards');
        Schema::dropIfExists('supplier_quote_items');
        Schema::dropIfExists('supplier_quotes');
        Schema::dropIfExists('request_for_quote_invitations');
        Schema::dropIfExists('request_for_quote_items');
        Schema::dropIfExists('request_for_quotes');
    }
};
