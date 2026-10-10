<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->string('number', 50);
            $table->date('date')->nullable();
            $table->decimal('gross_total', 15, 2);
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('portal_user_id')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX supplier_invoices_vendor_live_number_unique ON supplier_invoices (vendor_id, number) WHERE voided_at IS NULL');

        Schema::table('bills', function (Blueprint $table): void {
            $table->foreignId('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->restrictOnDelete();
        });
        Schema::table('portal_shipping_documents', function (Blueprint $table): void {
            $table->foreignId('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->restrictOnDelete();
        });

        DB::table('bills')
            ->whereNotNull('supplier_invoice_number')
            ->where('status', '<>', 'cancelled')
            ->orderBy('id')
            ->chunkById(100, function ($bills): void {
                foreach ($bills as $bill) {
                    $invoiceId = DB::table('supplier_invoices')->insertGetId([
                        'vendor_id' => $bill->vendor_id,
                        'purchase_order_id' => $bill->purchase_order_id,
                        'number' => $bill->supplier_invoice_number,
                        'date' => $bill->supplier_invoice_date,
                        'gross_total' => $bill->total_amount,
                        'submitted_at' => $bill->supplier_invoice_submitted_at,
                        'portal_user_id' => $bill->supplier_invoice_portal_user_id,
                        'created_at' => $bill->created_at,
                        'updated_at' => $bill->updated_at,
                    ]);
                    DB::table('bills')->where('id', $bill->id)->update(['supplier_invoice_id' => $invoiceId]);
                    DB::table('portal_shipping_documents')
                        ->where('bill_id', $bill->id)
                        ->where('document_type', 'supplier_invoice')
                        ->update(['supplier_invoice_id' => $invoiceId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('portal_shipping_documents', fn (Blueprint $table) => $table->dropConstrainedForeignId('supplier_invoice_id'));
        Schema::table('bills', fn (Blueprint $table) => $table->dropConstrainedForeignId('supplier_invoice_id'));
        Schema::dropIfExists('supplier_invoices');
    }
};
