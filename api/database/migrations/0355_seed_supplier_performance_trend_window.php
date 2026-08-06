<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'purchasing.supplier_score.trend_months',
            'value' => json_encode(6),
            'group' => 'purchasing',
            'label' => 'Supplier Performance Trend Months',
            'description' => 'Default number of monthly supplier performance snapshots shown in the trend view.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'purchasing.supplier_score.trend_months')->delete();
    }
};
