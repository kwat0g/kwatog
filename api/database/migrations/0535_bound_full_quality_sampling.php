<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.full_sampling.max_units',
            'value' => json_encode(1000),
            'group' => 'quality',
            'label' => 'Full Sampling Maximum Units',
            'description' => 'Hard upper bound for full-sampling measurement rows. Larger batches must use a finite quality plan.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.full_sampling.max_units')->delete();
    }
};
