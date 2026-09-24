<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A GRN-sourced bill line is priced at the receipt's delivered unit cost
 * (PO price + agreed RFQ freight/charges spread per unit), which is carried
 * at 4 dp on goods_receipt_note_items.unit_cost. A 2-dp bill line rounded
 * that away: qty × shown price stopped equalling the line total, and the
 * 3-way match re-read the rounded price and flagged a price variance on
 * cheap parts. Line totals stay decimal(15,2).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bill_items ALTER COLUMN unit_price TYPE numeric(15,4)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bill_items ALTER COLUMN unit_price TYPE numeric(15,2)');
    }
};
