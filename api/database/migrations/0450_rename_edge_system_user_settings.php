<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Scope cut — the Edge module was removed, but its service-account settings
 * are still needed by the B2B portals (SupplierPortalService /
 * CustomerPortalService write audit rows under non-web guards). Rename the
 * keys to drop the now-meaningless `edge.` prefix and regroup them under
 * `security`, so the Settings screen no longer advertises a module that
 * does not exist.
 */
return new class extends Migration {
    public function up(): void
    {
        // Insert first so fresh installs (which never ran 0413's rows through
        // this path) still get the keys, then drop any legacy rows.
        DB::table('settings')->insertOrIgnore([
            [
                'key' => 'system_user.email',
                'value' => json_encode('system@ogami.internal'),
                'group' => 'security',
                'label' => 'System User Email',
                'description' => 'Email of the service account that owns audit records created by automated and portal-originated writes.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'system_user.name',
                'value' => json_encode('System'),
                'group' => 'security',
                'label' => 'System User Name',
                'description' => 'Display name of the service account that owns audit records created by automated and portal-originated writes.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Carry over any operator-customised values from the legacy keys.
        foreach (['email', 'name'] as $field) {
            $legacy = DB::table('settings')->where('key', "edge.system_user.{$field}")->value('value');
            if ($legacy !== null) {
                DB::table('settings')
                    ->where('key', "system_user.{$field}")
                    ->update(['value' => $legacy, 'updated_at' => now()]);
            }
        }

        DB::table('settings')->whereIn('key', [
            'edge.system_user.email',
            'edge.system_user.name',
        ])->delete();
    }

    public function down(): void
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

        DB::table('settings')->whereIn('key', [
            'system_user.email',
            'system_user.name',
        ])->delete();
    }
};
