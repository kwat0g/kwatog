<?php

declare(strict_types=1);

use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the production-output → inventory receipt boundary explicit and
 * replayable. A production output remains a valid shop-floor fact even when
 * inventory master data or the stock ledger is temporarily unavailable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('work_order_outputs', 'production_receipt_handoff_status')) {
            Schema::table('work_order_outputs', function (Blueprint $table): void {
                $table->string('production_receipt_handoff_status', 30)
                    ->default(ProductionReceiptHandoffStatus::NotStarted->value);
                $table->text('production_receipt_handoff_message')->nullable();
                $table->timestamp('production_receipt_handoff_at')->nullable();
                $table->unsignedBigInteger('production_receipt_movement_id')->nullable();
                $table->foreign('production_receipt_movement_id')
                    ->references('id')
                    ->on('stock_movements')
                    ->nullOnDelete();
                $table->index('production_receipt_handoff_status');
                $table->index('production_receipt_movement_id');
            });
        }

        DB::table('work_order_outputs')
            ->where('good_count', '<=', 0)
            ->update([
                'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::NotRequired->value,
                'production_receipt_handoff_message' => null,
                'production_receipt_handoff_at' => DB::raw('COALESCE(production_receipt_handoff_at, recorded_at, CURRENT_TIMESTAMP)'),
            ]);

        // Legacy production receipts used only the parent WO as their
        // reference, so they cannot be assigned safely to an output when a WO
        // has multiple batches. Surface every historical good output for
        // explicit reconciliation; the retry service links the unambiguous
        // one-output/one-legacy-movement case without duplicating stock.
        DB::table('work_order_outputs')
            ->where('good_count', '>', 0)
            ->where('production_receipt_handoff_status', ProductionReceiptHandoffStatus::NotStarted->value)
            ->update([
                'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::ManualRequired->value,
                'production_receipt_handoff_message' => 'This historical production output needs finished-goods receipt reconciliation. Review Inventory, then replay the handoff.',
                'production_receipt_handoff_at' => DB::raw('COALESCE(production_receipt_handoff_at, recorded_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('work_order_outputs', 'production_receipt_handoff_status')) {
            return;
        }

        Schema::table('work_order_outputs', function (Blueprint $table): void {
            $table->dropForeign(['production_receipt_movement_id']);
            $table->dropIndex(['production_receipt_handoff_status']);
            $table->dropIndex(['production_receipt_movement_id']);
            $table->dropColumn([
                'production_receipt_handoff_status',
                'production_receipt_handoff_message',
                'production_receipt_handoff_at',
                'production_receipt_movement_id',
            ]);
        });
    }
};
