<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['maintenance.work_order.default_type', 'corrective', 'Maintenance Default Work Order Type'],
            ['maintenance.work_order.default_priority', 'medium', 'Maintenance Default Work Order Priority'],
        ] as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'maintenance',
                'label' => $label, 'description' => 'Default used when a maintenance work order omits this value.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['maintenance.work_order.default_type', 'maintenance.work_order.default_priority'])->delete();
    }
};
