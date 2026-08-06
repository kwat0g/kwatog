<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.ncr.escalation_roles',
            'value' => json_encode(['qc_inspector', 'production_manager', 'system_admin']),
            'group' => 'quality', 'label' => 'NCR Escalation Roles',
            'description' => 'Role slug per NCR escalation tier, in ascending order.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.ncr.escalation_roles')->delete();
    }
};
