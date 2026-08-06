<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['accounting.statements.current_period_net_income_code', '3099', 'Current Period Net Income Account Code'],
            ['accounting.statements.translation_adjustment_code', '3900', 'Cumulative Translation Adjustment Account Code'],
        ] as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'accounting',
                'label' => $label, 'description' => 'Account code used by generated financial statements.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'accounting.statements.current_period_net_income_code',
            'accounting.statements.translation_adjustment_code',
        ])->delete();
    }
};
