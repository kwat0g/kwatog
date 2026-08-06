<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.aql.sample_plan',
            'value' => json_encode([
                'tiny_batch' => ['code' => 'A', 'accept' => 0, 'reject' => 1],
                'rows' => [
                    ['max_lot' => 8, 'code' => 'G', 'sample_size' => 32, 'accept' => 0, 'reject' => 1],
                    ['max_lot' => 15, 'code' => 'G', 'sample_size' => 32, 'accept' => 0, 'reject' => 1],
                    ['max_lot' => 25, 'code' => 'G', 'sample_size' => 32, 'accept' => 0, 'reject' => 1],
                    ['max_lot' => 50, 'code' => 'G', 'sample_size' => 32, 'accept' => 0, 'reject' => 1],
                    ['max_lot' => 90, 'code' => 'G', 'sample_size' => 32, 'accept' => 0, 'reject' => 1],
                    ['max_lot' => 150, 'code' => 'G', 'sample_size' => 32, 'accept' => 0, 'reject' => 1],
                    ['max_lot' => 280, 'code' => 'G', 'sample_size' => 32, 'accept' => 0, 'reject' => 1],
                    ['max_lot' => 500, 'code' => 'H', 'sample_size' => 50, 'accept' => 1, 'reject' => 2],
                    ['max_lot' => 1200, 'code' => 'J', 'sample_size' => 80, 'accept' => 1, 'reject' => 2],
                    ['max_lot' => 3200, 'code' => 'K', 'sample_size' => 125, 'accept' => 2, 'reject' => 3],
                    ['max_lot' => 10000, 'code' => 'L', 'sample_size' => 200, 'accept' => 3, 'reject' => 4],
                    ['max_lot' => 35000, 'code' => 'M', 'sample_size' => 315, 'accept' => 5, 'reject' => 6],
                    ['max_lot' => 150000, 'code' => 'N', 'sample_size' => 500, 'accept' => 7, 'reject' => 8],
                    ['max_lot' => 500000, 'code' => 'P', 'sample_size' => 800, 'accept' => 10, 'reject' => 11],
                ],
                'overflow' => ['code' => 'Q', 'sample_size' => 1250, 'accept' => 14, 'reject' => 15],
            ]),
            'group' => 'quality',
            'label' => 'AQL Sample Plan',
            'description' => 'AQL lot-size/sample-size/acceptance table used for outgoing inspection plans.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.aql.sample_plan')->delete();
    }
};
