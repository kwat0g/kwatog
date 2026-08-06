<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.spc.xbar_r_constants',
            'value' => json_encode([
                2 => ['A2' => 1.880, 'D3' => 0.000, 'D4' => 3.267, 'd2' => 1.128],
                3 => ['A2' => 1.023, 'D3' => 0.000, 'D4' => 2.574, 'd2' => 1.693],
                4 => ['A2' => 0.729, 'D3' => 0.000, 'D4' => 2.282, 'd2' => 2.059],
                5 => ['A2' => 0.577, 'D3' => 0.000, 'D4' => 2.114, 'd2' => 2.326],
                6 => ['A2' => 0.483, 'D3' => 0.000, 'D4' => 2.004, 'd2' => 2.534],
                7 => ['A2' => 0.419, 'D3' => 0.076, 'D4' => 1.924, 'd2' => 2.704],
                8 => ['A2' => 0.373, 'D3' => 0.136, 'D4' => 1.864, 'd2' => 2.847],
                9 => ['A2' => 0.337, 'D3' => 0.184, 'D4' => 1.816, 'd2' => 2.970],
                10 => ['A2' => 0.308, 'D3' => 0.223, 'D4' => 1.777, 'd2' => 3.078],
            ]),
            'group' => 'quality',
            'label' => 'SPC X-bar/R Constants',
            'description' => 'A2, D3, D4, and d2 constants keyed by subgroup size for X-bar/R control limits.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.spc.xbar_r_constants')->delete();
    }
};
