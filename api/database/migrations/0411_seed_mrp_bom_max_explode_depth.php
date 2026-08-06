<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'mrp.bom.max_explode_depth',
            'value' => json_encode(10),
            'group' => 'mrp',
            'label' => 'Maximum BOM Explosion Depth',
            'description' => 'Maximum nested BOM depth allowed during recursive material explosion.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'mrp.bom.max_explode_depth')->delete();
    }
};
