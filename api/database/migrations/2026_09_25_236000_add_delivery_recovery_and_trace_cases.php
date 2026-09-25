<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_attempt_outcomes', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1);
        });
        Schema::table('delivery_attempt_outcome_movements', function (Blueprint $table): void {
            $table->decimal('customer_received_quantity', 15, 3)->nullable();
        });
        // Freeze customer custody by issue source before any later recovery.
        DB::table('delivery_attempt_outcome_items')
            ->whereNotNull('warehouse_received_quantity')->orderBy('id')->chunkById(200, function ($lines): void {
                foreach ($lines as $line) {
                    $remaining = (string) $line->customer_received_quantity;
                    $sources = DB::table('delivery_attempt_outcome_movements as source')
                        ->join('stock_movements as issue', 'issue.id', '=', 'source.stock_movement_id')
                        ->where('source.delivery_attempt_outcome_item_id', $line->id)->orderBy('source.id')
                        ->get(['source.id', 'source.received_quantity', 'issue.quantity']);
                    foreach ($sources as $source) {
                        $capacity = bcsub((string) $source->quantity, (string) ($source->received_quantity ?? '0'), 3);
                        $accepted = bccomp($remaining, $capacity, 3) < 0 ? $remaining : $capacity;
                        DB::table('delivery_attempt_outcome_movements')->where('id', $source->id)->update(['customer_received_quantity' => $accepted]);
                        $remaining = bcsub($remaining, $accepted, 3);
                    }
                    if (bccomp($remaining, '0', 3) !== 0) {
                        throw new RuntimeException('A reconciled delivery has inconsistent customer custody. Review its source movements before enabling late recovery.');
                    }
                }
            });
        Schema::create('delivery_attempt_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_attempt_outcome_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('payload_fingerprint', 64);
            $table->string('kind', 30);
            $table->text('reason');
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('return_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampsTz();
        });
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropUnique(['delivery_attempt_outcome_id']);
            $table->index('delivery_attempt_outcome_id');
        });
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->dropUnique(['delivery_attempt_outcome_movement_id']);
            $table->index('delivery_attempt_outcome_movement_id');
        });
        Schema::table('return_cases', function (Blueprint $table): void {
            $table->string('intake_kind', 30)->default('discrepancy')->index();
        });
    }

    public function down(): void
    {
        foreach (['return_requests' => 'delivery_attempt_outcome_id', 'return_request_items' => 'delivery_attempt_outcome_movement_id'] as $name => $column) {
            if (DB::table($name)->whereNotNull($column)->groupBy($column)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('This migration cannot roll back after multiple recovery receipts. Preserve the receipt audit trail.');
            }
        }
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropIndex(['delivery_attempt_outcome_id']);
            $table->unique('delivery_attempt_outcome_id');
        });
        Schema::table('return_request_items', function (Blueprint $table): void {
            $table->dropIndex(['delivery_attempt_outcome_movement_id']);
            $table->unique('delivery_attempt_outcome_movement_id');
        });
        Schema::table('return_cases', fn (Blueprint $table) => $table->dropColumn('intake_kind'));
        Schema::dropIfExists('delivery_attempt_revisions');
        Schema::table('delivery_attempt_outcome_movements', fn (Blueprint $table) => $table->dropColumn('customer_received_quantity'));
        Schema::table('delivery_attempt_outcomes', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
