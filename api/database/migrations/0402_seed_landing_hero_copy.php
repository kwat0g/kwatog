<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'landing.hero_copy',
            'value' => json_encode([
                'line_one' => 'Precision the',
                'line_two' => 'world trusts,',
                'line_three' => 'made in the Philippines.',
            ]),
            'group' => 'landing', 'label' => 'Landing Hero Copy',
            'description' => 'Headline lines displayed in the public landing hero.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'landing.hero_copy')->delete();
    }
};
