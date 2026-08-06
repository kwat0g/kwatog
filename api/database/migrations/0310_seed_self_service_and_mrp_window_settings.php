<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $rows = [
            ['key' => 'attendance.ot.request_min_hours', 'value' => 0.5, 'group' => 'attendance', 'label' => 'Self-service OT Minimum Hours', 'description' => 'Minimum overtime hours employees may request.'],
            ['key' => 'attendance.ot.request_max_hours', 'value' => 4.0, 'group' => 'attendance', 'label' => 'Self-service OT Maximum Hours', 'description' => 'Maximum overtime hours employees may request per day.'],
            ['key' => 'attendance.ot.request_future_days', 'value' => 30, 'group' => 'attendance', 'label' => 'Self-service OT Future Window', 'description' => 'Maximum number of days ahead an employee may request overtime.'],
            ['key' => 'attendance.ot.request_past_days', 'value' => 0, 'group' => 'attendance', 'label' => 'Self-service OT Past Window', 'description' => 'Number of days before today allowed for employee overtime requests.'],
            ['key' => 'mrp.schedule.default_horizon_days', 'value' => 14, 'group' => 'mrp', 'label' => 'MRP Schedule Horizon Days', 'description' => 'Default number of days included when viewing the scheduler snapshot.'],
        ];
        foreach ($rows as $row) {
            DB::table('settings')->insertOrIgnore([...$row, 'value' => json_encode($row['value']), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'attendance.ot.request_min_hours', 'attendance.ot.request_max_hours',
            'attendance.ot.request_future_days', 'attendance.ot.request_past_days',
            'mrp.schedule.default_horizon_days',
        ])->delete();
    }
};
