<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('shifts')) return;
        if (DB::table('shifts')->where('is_default', true)->where('is_active', true)->exists()) return;

        DB::table('shifts')->updateOrInsert(
            ['name' => 'Day Shift'],
            ['start_time' => '06:00', 'end_time' => '14:00', 'break_minutes' => 30, 'is_night_shift' => false, 'is_extended' => false, 'auto_ot_hours' => null, 'is_default' => true, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function down(): void {}
};
