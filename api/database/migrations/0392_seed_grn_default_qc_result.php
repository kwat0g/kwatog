<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'inventory.grn.default_qc_result',
            'value' => json_encode('pending'),
            'group' => 'inventory',
            'label' => 'Default GRN QC Result',
            'description' => 'Result used when an internal GRN receipt call omits a QC verdict; must not imply acceptance.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'inventory.grn.default_qc_result')->delete();
    }
};
