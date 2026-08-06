<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'dashboard.admin.pending_jobs_warning_threshold',
            'value' => json_encode(100), 'group' => 'dashboard',
            'label' => 'Admin Pending Jobs Warning Threshold',
            'description' => 'Maximum pending queue jobs considered healthy on the admin dashboard.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'dashboard.admin.pending_jobs_warning_threshold')->delete();
    }
};
