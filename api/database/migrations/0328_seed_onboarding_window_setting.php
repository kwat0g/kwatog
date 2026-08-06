<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'hr.onboarding.stale_days', 'value' => json_encode(3),
            'group' => 'hr', 'label' => 'Onboarding Reminder Window (Days)',
            'description' => 'Days before an incomplete onboarding receives a reminder.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'hr.onboarding.stale_days')->delete();
    }
};
