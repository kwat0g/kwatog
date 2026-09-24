<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Replace non-existent 'quality' role slug with seeded 'production_manager' in 8D notification roles. */
return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'crm.complaint_8d.notification_roles')
            ->update([
                'value' => json_encode(['qc_inspector', 'production_manager']),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'crm.complaint_8d.notification_roles')
            ->update([
                'value' => json_encode(['quality', 'qc_inspector']),
                'updated_at' => now(),
            ]);
    }
};
