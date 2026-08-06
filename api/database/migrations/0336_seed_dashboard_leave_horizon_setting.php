<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'dashboard.widgets.leave_calendar_horizon_days',
            'value' => json_encode(7),
            'group' => 'dashboard',
            'label' => 'Leave Calendar Horizon Days',
            'description' => 'Forward window shown by the HR dashboard leave calendar.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'dashboard.widgets.leave_calendar_horizon_days')->delete();
    }
};
