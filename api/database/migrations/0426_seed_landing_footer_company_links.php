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
        $copy['footer_company_links'] = [
            ['label' => 'About us', 'href' => '#top'],
            ['label' => 'Careers', 'href' => '/careers'],
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
        unset($copy['footer_company_links']);
        DB::table('settings')->where('key', 'landing.section_copy')->update([
            'value' => json_encode($copy),
            'updated_at' => now(),
        ]);
    }
};
