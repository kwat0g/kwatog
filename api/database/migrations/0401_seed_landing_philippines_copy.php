<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'landing.philippines_copy',
            'value' => json_encode([
                'eyebrow' => 'Filipino-made',
                'title' => 'World-class precision, proudly made at home.',
                'body' => "{{company}} proves that the precision the world's automakers demand can be engineered right here in Cavite. Every part is shaped by skilled Filipino hands, held to the same standard trusted on assembly lines across the globe.",
            ]),
            'group' => 'landing', 'label' => 'Landing Philippines Section Copy',
            'description' => 'Copy displayed in the public Filipino-made section.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'landing.philippines_copy')->delete();
    }
};
