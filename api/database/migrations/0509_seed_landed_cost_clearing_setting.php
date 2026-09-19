<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'accounting.accounts.landed_cost_clearing_code',
            'value' => json_encode('2120'),
            'group' => 'accounting',
            'label' => 'Landed Cost Clearing Account Code',
            'description' => 'Liability account credited when imported landed costs are capitalized into inventory.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'accounting.accounts.landed_cost_clearing_code')->delete();
    }
};
