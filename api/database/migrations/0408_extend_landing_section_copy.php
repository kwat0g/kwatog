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
        $copy += [
            'footer_description' => '{{company}} — precision manufacturing and supply-chain support, delivered with traceability.',
            'newsletter_description' => 'Quality tips, process notes, and {{company}} news — sent sparingly.',
            'page_title_suffix' => 'Precision manufacturing',
        ];
        DB::table('settings')->where('key', 'landing.section_copy')->update(['value' => json_encode($copy), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        if (! $row) return;
        $copy = (array) json_decode((string) $row->value, true);
        unset($copy['footer_description'], $copy['newsletter_description'], $copy['page_title_suffix']);
        DB::table('settings')->where('key', 'landing.section_copy')->update(['value' => json_encode($copy), 'updated_at' => now()]);
    }
};
