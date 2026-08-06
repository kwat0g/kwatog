<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'inventory.stock_count.variance_tolerance_pct',
            'value' => json_encode(2),
            'group' => 'inventory',
            'label' => 'Stock Count Variance Approval Tolerance',
            'description' => 'Absolute variance percentage requiring supervisor approval during stock count completion.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'inventory.stock_count.variance_tolerance_pct')->delete();
    }
};
