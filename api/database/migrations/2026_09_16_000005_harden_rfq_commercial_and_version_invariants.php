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
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->decimal('rfq_vat_amount', 15, 2)->nullable()->after('vat_amount');
            $table->decimal('rfq_freight_amount', 15, 2)->nullable()->after('rfq_vat_amount');
            $table->decimal('rfq_other_charges', 15, 2)->nullable()->after('rfq_freight_amount');
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->decimal('rfq_line_vat_amount', 15, 2)->nullable()->after('supplier_quote_version_id');
            $table->decimal('rfq_line_freight_amount', 15, 2)->nullable()->after('rfq_line_vat_amount');
            $table->decimal('rfq_line_other_charges', 15, 2)->nullable()->after('rfq_line_freight_amount');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX supplier_quotes_current_submitted_unique
            ON supplier_quotes (request_for_quote_id, vendor_id)
            WHERE is_current = TRUE AND status = 'submitted'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS supplier_quotes_current_submitted_unique');

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropColumn([
                'rfq_line_vat_amount',
                'rfq_line_freight_amount',
                'rfq_line_other_charges',
            ]);
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'rfq_vat_amount',
                'rfq_freight_amount',
                'rfq_other_charges',
            ]);
        });
    }
};
