<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record the supplier's own invoice on the AP bill.
 *
 * AutoCreateBillOnGrnAccepted stages a draft bill (internal sequence number)
 * the moment QC accepts a receipt. The supplier portal then refused the
 * supplier's invoice for that receipt with "an AP bill already exists" and
 * dropped the invoice number and attachment on the floor — the common case,
 * not an edge case. The supplier's reference now lives in its own columns so
 * it can attach to the staged bill instead of competing with it.
 *
 * Timestamp-named because `bills` is altered by 2026_* migrations
 * (latest: 2026_09_20_140000_drop_bills_goods_receipt_note_unique).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->string('supplier_invoice_number', 50)->nullable()->after('bill_number');
            $table->date('supplier_invoice_date')->nullable()->after('supplier_invoice_number');
            $table->timestamp('supplier_invoice_submitted_at')->nullable()->after('supplier_invoice_date');
            $table->foreignId('supplier_invoice_portal_user_id')->nullable()->after('supplier_invoice_submitted_at')
                ->constrained('supplier_portal_users')->nullOnDelete();
        });

        // Bills the portal created before this migration carried the
        // supplier's number as bill_number. The portal audit row identifies them.
        DB::statement(<<<'SQL'
            UPDATE bills
               SET supplier_invoice_number = bills.bill_number,
                   supplier_invoice_date = bills.date,
                   supplier_invoice_submitted_at = audit_logs.created_at
              FROM audit_logs
             WHERE audit_logs.action = 'supplier_inv.submit'
               AND audit_logs.model_type = 'App\Modules\Accounting\Models\Bill'
               AND audit_logs.model_id = bills.id
               AND bills.supplier_invoice_number IS NULL
        SQL);

        // One live bill per supplier invoice number per vendor. A cancelled
        // bill releases the number so the supplier can resubmit it.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX bills_vendor_supplier_invoice_unique
                ON bills (vendor_id, supplier_invoice_number)
             WHERE supplier_invoice_number IS NOT NULL AND status <> 'cancelled'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bills_vendor_supplier_invoice_unique');

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_invoice_portal_user_id');
            $table->dropColumn(['supplier_invoice_number', 'supplier_invoice_date', 'supplier_invoice_submitted_at']);
        });
    }
};
