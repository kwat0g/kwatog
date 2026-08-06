<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            [
                'key' => 'edge.system_user.email',
                'value' => json_encode('edge-system@ogami.internal'),
                'group' => 'edge',
                'label' => 'Edge System User Email',
                'description' => 'Email used for the service user that owns audit records created by edge-device ingestion.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'edge.system_user.name',
                'value' => json_encode('Edge System'),
                'group' => 'edge',
                'label' => 'Edge System User Name',
                'description' => 'Display name used for the service user that owns audit records created by edge-device ingestion.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'edge.system_user.email',
            'edge.system_user.name',
        ])->delete();
    }
};
