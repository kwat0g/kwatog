<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'purchasing.auto_po.approval_roles',
            'value' => json_encode(['system_admin', 'production_manager']),
            'group' => 'purchasing',
            'label' => 'Automatic PO Approval Roles',
            'description' => 'Active role slugs that receive approval notifications for automatically generated purchase orders.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'purchasing.auto_po.approval_roles')->delete();
    }
};
