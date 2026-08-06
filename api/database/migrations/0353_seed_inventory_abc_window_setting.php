<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'inventory.abc.history_months',
            'value' => json_encode(12),
            'group' => 'inventory',
            'label' => 'ABC Usage History (Months)',
            'description' => 'Trailing usage window used when calculating annualized ABC inventory value.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'inventory.abc.history_months')->delete();
    }
};
