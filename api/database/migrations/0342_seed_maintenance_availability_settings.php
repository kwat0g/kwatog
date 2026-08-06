<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['maintenance.downtime.availability_good_ratio', 0.95, 'Maintenance Availability Good Ratio'],
        ['maintenance.downtime.availability_warning_ratio', 0.85, 'Maintenance Availability Warning Ratio'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'maintenance',
                'label' => $label, 'description' => 'Availability classification threshold for downtime analytics.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
