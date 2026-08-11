<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'dashboard.widgets.maintenance_horizon_days',
            'value' => json_encode(14),
            'group' => 'dashboard',
            'label' => 'Dashboard Maintenance Horizon Days',
            'description' => 'Time window used by dashboard widgets.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'dashboard.widgets.maintenance_horizon_days')->delete();
    }
};
