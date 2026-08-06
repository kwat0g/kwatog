<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ROWS = [
        ['mrp.work_order.urgent_delivery_days', 7, 'mrp', 'MRP Urgent Delivery Horizon', 'Days from today within which an MRP-generated work order receives urgent priority.'],
        ['mrp.work_order.urgent_priority', 100, 'mrp', 'MRP Urgent Work Order Priority', 'Numeric priority assigned to MRP-generated work orders inside the urgent horizon.'],
        ['mrp.work_order.normal_priority', 50, 'mrp', 'MRP Normal Work Order Priority', 'Numeric priority assigned to MRP-generated work orders outside the urgent horizon.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => $group,
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
