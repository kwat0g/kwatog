<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'inventory.safety_stock.issue_movement_types',
            'value' => json_encode(['material_issue', 'delivery', 'adjustment_out', 'scrap', 'return_to_vendor']),
            'group' => 'inventory',
            'label' => 'Safety Stock Issue Movement Types',
            'description' => 'Stock movement types included in demand history for automatic safety-stock calculation.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'inventory.safety_stock.issue_movement_types')->delete();
    }
};
