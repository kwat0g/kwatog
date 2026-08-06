<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        $copy = $row ? (array) json_decode((string) $row->value, true) : [];
        $copy['nav_links'] = [
            ['label' => 'Capabilities', 'href' => '#capabilities'],
            ['label' => 'Process', 'href' => '#process'],
            ['label' => 'Quality', 'href' => '#quality'],
            ['label' => 'Filipino-made', 'href' => '#filipino-made'],
            ['label' => 'Contact', 'href' => '#contact'],
        ];

        if ($row) {
            DB::table('settings')->where('key', 'landing.section_copy')->update([
                'value' => json_encode($copy),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('settings')->insert([
                'key' => 'landing.section_copy',
                'value' => json_encode($copy),
                'group' => 'landing',
                'label' => 'Landing Section Copy',
                'description' => 'Introductory copy and navigation used by public landing-page sections.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        if (! $row) return;
        $copy = (array) json_decode((string) $row->value, true);
        unset($copy['nav_links']);
        DB::table('settings')->where('key', 'landing.section_copy')->update([
            'value' => json_encode($copy),
            'updated_at' => now(),
        ]);
    }
};
