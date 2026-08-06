<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'production.dashboard.defect_history_days', 'value' => json_encode(7),
            'group' => 'production', 'label' => 'Production Defect History (Days)',
            'description' => 'History window used by the plant dashboard defect Pareto.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'production.dashboard.defect_history_days')->delete();
    }
};
