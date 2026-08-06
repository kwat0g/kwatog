<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'accounting.customer_credit.warning_ratio',
            'value' => json_encode(0.80),
            'group' => 'accounting',
            'label' => 'Customer Credit Warning Ratio',
            'description' => 'Credit utilization ratio at which a customer is flagged for review.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'accounting.customer_credit.warning_ratio')->delete();
    }
};
