<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.ncr.default_disposition',
            'value' => json_encode('rework'),
            'group' => 'quality',
            'label' => 'Default NCR Disposition',
            'description' => 'Disposition applied when bulk NCR closure adds corrective action without an existing disposition.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.ncr.default_disposition')->delete();
    }
};
