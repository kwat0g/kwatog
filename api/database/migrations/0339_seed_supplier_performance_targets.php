<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['purchasing.supplier_score.on_time_target', 95, 'Supplier On-time Delivery Target'],
        ['purchasing.supplier_score.quality_target', 98, 'Supplier Quality Pass Target'],
        ['purchasing.supplier_score.price_variance_target', 5, 'Supplier Price Variance Target'],
        ['purchasing.supplier_score.lead_time_variance_target', 2, 'Supplier Lead-time Variance Target'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => 'purchasing',
                'label' => $label,
                'description' => 'Configurable supplier performance target.',
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
