<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.dashboard.defect_history_days', 'value' => json_encode(30), 'group' => 'quality',
            'label' => 'Quality Defect History Days', 'description' => 'Default lookback window for defect Pareto analytics.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.dashboard.defect_history_days')->delete();
    }
};
