<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.aql.default_level',
            'value' => json_encode('general_ii'),
            'group' => 'quality',
            'label' => 'Default AQL Level',
            'description' => 'AQL inspection level used when an incoming quality plan does not specify one.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.aql.default_level')->delete();
    }
};
