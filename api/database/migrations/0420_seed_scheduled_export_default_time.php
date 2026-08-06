<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'exports.default_time_of_day',
            'value' => json_encode('06:00'),
            'group' => 'exports',
            'label' => 'Scheduled Export Default Time',
            'description' => 'Default 24-hour time used when a scheduled export does not specify a time of day.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'exports.default_time_of_day')->delete();
    }
};
