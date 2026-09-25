<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_number', 32)->unique();
            $table->string('type', 20);
            $table->string('status', 30)->default('submitted');

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained('deliveries')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('goods_receipt_note_id')->nullable()->constrained('goods_receipt_notes')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_portal_user_id')->nullable()->constrained('customer_portal_users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('preferred_resolution', 20);
            $table->string('resolution', 20)->nullable();
            $table->text('description');
            $table->text('resolution_notes')->nullable();
            $table->date('expected_date')->nullable();

            $table->string('request_key', 64)->nullable();
            $table->string('request_fingerprint', 64)->nullable();

            $table->foreignId('return_request_id')->nullable()->unique()->constrained('return_requests')->nullOnDelete();
            $table->foreignId('credit_note_id')->nullable()->constrained('credit_notes')->nullOnDelete();
            $table->foreignId('replacement_sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('replacement_purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('replacement_delivery_id')->nullable()->constrained('deliveries')->nullOnDelete();
            $table->foreignId('resolution_goods_receipt_note_id')->nullable()->constrained('goods_receipt_notes')->nullOnDelete();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'request_key'], 'return_cases_creator_request_key_unique');
            $table->index(['status', 'created_at']);
            $table->index(['type', 'status']);
            $table->index('customer_id');
            $table->index('vendor_id');
            $table->index('delivery_id');
            $table->index('purchase_order_id');
            $table->index('goods_receipt_note_id');
            $table->index('assigned_to');
        });

        Schema::create('return_case_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_case_id')->constrained('return_cases')->cascadeOnDelete();
            $table->foreignId('source_delivery_item_id')->nullable()->constrained('delivery_items')->nullOnDelete();
            $table->foreignId('source_po_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->foreignId('source_grn_item_id')->nullable()->constrained('grn_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();

            $table->string('description', 255);
            $table->string('unit', 40);
            $table->decimal('expected_quantity', 15, 3);
            $table->decimal('received_quantity', 15, 3)->default(0);
            $table->decimal('missing_quantity', 15, 3)->default(0);
            $table->decimal('defective_quantity', 15, 3)->default(0);
            $table->decimal('verified_missing_quantity', 15, 3)->nullable();
            $table->decimal('verified_defective_quantity', 15, 3)->nullable();
            $table->string('lot_number', 120)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->decimal('source_unit_price', 15, 4);
            $table->timestamps();

            $table->index('source_delivery_item_id');
            $table->index('source_po_item_id');
            $table->index('source_grn_item_id');
            $table->index(['product_id', 'item_id']);
        });

        // Quantities must never become negative. Verified shortage calculations
        // remain service-owned because they depend on the source document.
        DB::statement(
            'ALTER TABLE "return_case_lines" ADD CONSTRAINT "return_case_lines_quantity_sanity_check" '
            .'CHECK ("expected_quantity" >= 0 AND "received_quantity" >= 0 AND "missing_quantity" >= 0 '
            .'AND "defective_quantity" >= 0 '
            .'AND ("verified_missing_quantity" IS NULL OR "verified_missing_quantity" >= 0) '
            .'AND ("verified_defective_quantity" IS NULL OR "verified_defective_quantity" >= 0) '
            .'AND "defective_quantity" <= "received_quantity" '
            .'AND ("verified_defective_quantity" IS NULL OR "verified_defective_quantity" <= "received_quantity"))',
        );

        Schema::create('return_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_case_id')->constrained('return_cases')->cascadeOnDelete();
            $table->string('action', 40);
            $table->text('message')->nullable();
            $table->string('actor_type', 20);
            $table->string('actor_name', 150);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_portal_user_id')->nullable()->constrained('customer_portal_users')->nullOnDelete();
            $table->foreignId('supplier_portal_user_id')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['return_case_id', 'created_at']);
            $table->index(['return_case_id', 'is_public']);
        });

        Schema::create('return_case_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_case_id')->constrained('return_cases')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('return_case_events')->nullOnDelete();
            $table->string('file_name', 255);
            $table->string('path', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_portal_user_id')->nullable()->constrained('customer_portal_users')->nullOnDelete();
            $table->foreignId('supplier_portal_user_id')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->timestamps();

            $table->index(['return_case_id', 'created_at']);
        });

        $this->registerCaseSequence();
    }

    public function down(): void
    {
        $this->removeCaseSequence();

        Schema::dropIfExists('return_case_attachments');
        Schema::dropIfExists('return_case_events');
        Schema::dropIfExists('return_case_lines');
        Schema::dropIfExists('return_cases');
    }

    private function registerCaseSequence(): void
    {
        $row = DB::table('settings')->where('key', 'documents.sequence_config')->first();
        if (! $row) {
            return;
        }

        $config = (array) json_decode((string) $row->value, true);
        $config['return_case'] = ['prefix' => 'CASE', 'reset' => 'monthly', 'pad' => 4];
        DB::table('settings')->where('key', 'documents.sequence_config')->update([
            'value' => json_encode($config),
            'updated_at' => now(),
        ]);
    }

    private function removeCaseSequence(): void
    {
        $row = DB::table('settings')->where('key', 'documents.sequence_config')->first();
        if (! $row) {
            return;
        }

        $config = (array) json_decode((string) $row->value, true);
        unset($config['return_case']);
        DB::table('settings')->where('key', 'documents.sequence_config')->update([
            'value' => json_encode($config),
            'updated_at' => now(),
        ]);
    }
};
