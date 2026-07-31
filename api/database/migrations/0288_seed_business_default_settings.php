<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['sales.default_customer_payment_terms_days', 30, 'sales', 'Default Customer Payment Terms', 'Default payment terms for new customers when no explicit value is supplied.'],
        ['purchasing.default_vendor_payment_terms_days', 30, 'purchasing', 'Default Vendor Payment Terms', 'Default payment terms for new vendors when no explicit value is supplied.'],
        ['sales.default_delivery_lead_days', 30, 'sales', 'Default Sales Delivery Lead Days', 'Delivery lead time used when converting a quote without a valid-until date.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => $group,
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
