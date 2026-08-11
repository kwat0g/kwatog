<?php

declare(strict_types=1);

use App\Modules\SupplyChain\Enums\DeliveryInvoiceHandoffStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the O2C delivery → AR-invoice handoff an explicit persisted state.
 *
 * A confirmed delivery is a valid business result even when Accounting is
 * unavailable. The missing invoice must therefore be recoverable without
 * inferring state from a log line or notification row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('deliveries', 'invoice_handoff_status')) {
            Schema::table('deliveries', function (Blueprint $table): void {
                $table->string('invoice_handoff_status', 30)
                    ->default(DeliveryInvoiceHandoffStatus::NotStarted->value)
                    ->index();
                $table->text('invoice_handoff_message')->nullable();
                $table->timestamp('invoice_handoff_at')->nullable();
            });
        }

        DB::table('deliveries')
            ->whereNotNull('invoice_id')
            ->update([
                'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::Generated->value,
                'invoice_handoff_message' => null,
                'invoice_handoff_at' => DB::raw('COALESCE(invoice_handoff_at, confirmed_at, updated_at)'),
            ]);

        DB::table('deliveries')
            ->where('status', 'confirmed')
            ->whereNull('invoice_id')
            ->update([
                'invoice_handoff_status' => DeliveryInvoiceHandoffStatus::ManualRequired->value,
                'invoice_handoff_message' => 'This confirmed delivery has no linked customer invoice. Review Accounting and create or replay the invoice handoff.',
                'invoice_handoff_at' => DB::raw('COALESCE(invoice_handoff_at, confirmed_at, updated_at)'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('deliveries', 'invoice_handoff_status')) {
            return;
        }

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropIndex(['invoice_handoff_status']);
            $table->dropColumn([
                'invoice_handoff_status',
                'invoice_handoff_message',
                'invoice_handoff_at',
            ]);
        });
    }
};
