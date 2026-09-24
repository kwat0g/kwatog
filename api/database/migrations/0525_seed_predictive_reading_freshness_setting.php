<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'maintenance.predictive.max_reading_age_hours'],
            [
                'value' => json_encode(24),
                'group' => 'maintenance',
                'label' => 'Predictive Reading Maximum Age (hours)',
                'description' => 'Older or future-dated condition readings are excluded from automatic corrective-work-order evaluation.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'maintenance.predictive.max_reading_age_hours')->delete();
    }
};
