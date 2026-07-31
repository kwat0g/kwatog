<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'tax.ph.vat_rate',
            'value' => json_encode(0.12),
            'group' => 'tax',
            'label' => 'Philippine VAT Rate',
            'description' => 'Decimal VAT rate applied to VATable quotes, orders, invoices, bills, and credit notes.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'tax.ph.vat_rate')->delete();
    }
};
