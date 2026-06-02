<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_count_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('session_number', 30)->unique();
            $table->string('title', 200);
            $table->string('scope', 50)->default('full');          // full, zone, category
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('warehouse_zones')->nullOnDelete();
            $table->string('status', 20)->default('draft');         // draft, in_progress, frozen, completed, cancelled
            $table->integer('total_locations')->default(0);
            $table->integer('counted_locations')->default(0);
            $table->integer('variance_count')->default(0);
            $table->decimal('variance_value', 15, 2)->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('frozen_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('stock_count_sessions')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('warehouse_locations')->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->decimal('system_quantity', 15, 3)->default(0);
            $table->decimal('counted_quantity', 15, 3)->nullable();
            $table->decimal('variance', 15, 3)->default(0);
            $table->decimal('variance_percent', 8, 2)->default(0);
            $table->string('lot_number', 50)->nullable();
            $table->string('status', 20)->default('pending');      // pending, counted, verified, adjusted
            $table->foreignId('counted_by')->nullable()->constrained('users');
            $table->timestamp('counted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'location_id', 'item_id'], 'stock_count_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_count_sessions');
    }
};
