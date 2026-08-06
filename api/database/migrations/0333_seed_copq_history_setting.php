<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.copq.default_history_months',
            'value' => json_encode(12),
            'group' => 'quality',
            'label' => 'COPQ Default History Months',
            'description' => 'Default number of monthly COPQ snapshots shown by the analytics endpoint.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.copq.default_history_months')->delete();
    }
};
