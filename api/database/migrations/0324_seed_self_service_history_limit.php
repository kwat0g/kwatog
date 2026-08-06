<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'self_service.history_limit', 'value' => json_encode(60),
            'group' => 'hr', 'label' => 'Self-Service History Limit',
            'description' => 'Maximum historical self-service rows returned per request.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'self_service.history_limit')->delete();
    }
};
