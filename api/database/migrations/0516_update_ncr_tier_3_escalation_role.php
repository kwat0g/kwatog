<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Replace system_admin with vice_president as tier-3 NCR escalation role. */
return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'quality.ncr.escalation_roles')
            ->update([
                'value' => json_encode(['qc_inspector', 'production_manager', 'vice_president']),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'quality.ncr.escalation_roles')
            ->update([
                'value' => json_encode(['qc_inspector', 'production_manager', 'system_admin']),
                'updated_at' => now(),
            ]);
    }
};
