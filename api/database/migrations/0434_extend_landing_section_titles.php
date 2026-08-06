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
            'capabilities_title' => 'One partner, from raw resin to finished assembly.',
            'process_title' => 'Six controlled steps from resin to certified part.',
            'part_showcase_title' => 'Turn it over. Take it apart.',
        ];
        DB::table('settings')->where('key', 'landing.section_copy')->update(['value' => json_encode($copy), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        if (! $row) return;
        $copy = (array) json_decode((string) $row->value, true);
        foreach (['capabilities_title', 'process_title', 'part_showcase_title'] as $key) unset($copy[$key]);
        DB::table('settings')->where('key', 'landing.section_copy')->update(['value' => json_encode($copy), 'updated_at' => now()]);
    }
};
