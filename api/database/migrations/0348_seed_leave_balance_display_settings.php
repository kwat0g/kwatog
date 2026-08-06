<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['leave.balance.display_warning_ratio', 0.50, 'Leave Balance Display Warning Ratio'],
        ['leave.balance.display_critical_ratio', 0.20, 'Leave Balance Display Critical Ratio'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'leave',
                'label' => $label,
                'description' => 'Remaining leave ratio used by self-service balance indicators.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
