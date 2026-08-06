<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'landing.quality_policy.effective_date',
            'value' => json_encode('January 2025'),
            'group' => 'landing',
            'label' => 'Quality Policy Effective Date',
            'description' => 'Effective date printed on the downloadable public quality policy.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'landing.quality_policy.effective_date')->delete();
    }
};
