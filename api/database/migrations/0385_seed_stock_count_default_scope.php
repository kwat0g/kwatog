<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'inventory.stock_count.default_scope',
            'value' => json_encode('full'),
            'group' => 'inventory',
            'label' => 'Default Stock Count Scope',
            'description' => 'Scope used when a stock-count session omits an explicit scope.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'inventory.stock_count.default_scope')->delete();
    }
};
