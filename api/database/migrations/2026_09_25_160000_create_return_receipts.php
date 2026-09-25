<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('return_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->nullable();
            $table->char('payload_fingerprint', 64);
            $table->boolean('final_receipt')->default(true);
            $table->foreignId('quarantine_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['return_request_id', 'request_key'], 'return_receipts_request_key_unique');
            $table->index(['return_request_id', 'received_at']);
        });

        Schema::create('return_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_receipt_id')->constrained('return_receipts')->cascadeOnDelete();
            $table->foreignId('return_request_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamps();

            $table->unique(['return_receipt_id', 'return_request_item_id'], 'return_receipt_items_line_unique');
            $table->index('return_request_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_receipt_items');
        Schema::dropIfExists('return_receipts');
    }
};
