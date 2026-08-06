<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'maintenance.mold_schedule.trigger_threshold_pct',
            'value' => json_encode(100),
            'group' => 'maintenance',
            'label' => 'Mold Schedule Trigger Threshold (%)',
            'description' => 'Mold shot-utilisation percentage at which preventive maintenance work orders are materialised.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'maintenance.mold_schedule.trigger_threshold_pct')->delete();
    }
};
