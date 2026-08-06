<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        if (! $row) return;
        $copy = (array) json_decode((string) $row->value, true);
        $copy['hero_cta'] = [
            'quote_label' => 'Request a quote',
            'quote_href' => '#contact',
            'explore_label' => 'Explore capabilities',
            'explore_href' => '#capabilities',
            'careers_label' => 'Careers',
            'careers_href' => '/careers',
        ];
        DB::table('settings')->where('key', 'landing.section_copy')->update([
            'value' => json_encode($copy),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        if (! $row) return;
        $copy = (array) json_decode((string) $row->value, true);
        unset($copy['hero_cta']);
        DB::table('settings')->where('key', 'landing.section_copy')->update([
            'value' => json_encode($copy),
            'updated_at' => now(),
        ]);
    }
};
