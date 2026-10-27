<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.effectiveness.due_soon_days',
            'value' => json_encode(3),
            'group' => 'quality',
            'label' => 'CAPA Effectiveness Due-soon Days',
            'description' => 'Days ahead used by the current owner effectiveness due badge and queue.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve operator-edited settings when rolling back application code.
    }
};
