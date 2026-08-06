<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'dashboard.chain_bottlenecks.result_limit',
            'value' => json_encode(50),
            'group' => 'dashboard',
            'label' => 'Chain Bottleneck Result Limit',
            'description' => 'Maximum stuck records returned by each chain bottleneck detector.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'dashboard.chain_bottlenecks.result_limit')->delete();
    }
};
