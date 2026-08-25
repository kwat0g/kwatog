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
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->foreignId('rejected_by')
                ->nullable()
                ->after('approved_by')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->boolean('receipt_recorded')
                ->default(false)
                ->after('returned_quantity');
            $table->index(['return_request_id', 'receipt_recorded']);
        });

        // Existing positive counts were explicit physical receipts in the
        // pre-flag schema. Zero remains intentionally unrecorded because the
        // old default cannot distinguish “not counted” from “none returned”.
        DB::table('return_request_items')
            ->where('returned_quantity', '>', 0)
            ->update(['receipt_recorded' => true]);

        Schema::create('return_request_source_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_item_id')
                ->constrained('return_request_items')
                ->cascadeOnDelete();
            $table->string('source_kind', 40);
            $table->unsignedBigInteger('source_id');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 14, 2);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['source_kind', 'source_id', 'released_at']);
            $table->index(['return_request_item_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_request_source_allocations');

        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rejected_by');
        });

        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->dropIndex(['return_request_id', 'receipt_recorded']);
            $table->dropColumn('receipt_recorded');
        });
    }
};
