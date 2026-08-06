<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'dashboard.widgets.ap_due_horizon_days',
            'value' => json_encode(7),
            'group' => 'dashboard',
            'label' => 'AP Due Horizon Days',
            'description' => 'Forward window used by the finance dashboard for bills coming due.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'dashboard.widgets.ap_due_horizon_days')->delete();
    }
};
