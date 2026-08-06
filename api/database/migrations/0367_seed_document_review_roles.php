<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.document_review.recipient_roles',
            'value' => json_encode(['system_admin', 'qc_inspector']),
            'group' => 'quality',
            'label' => 'Document Review Recipient Roles',
            'description' => 'Active role slugs that receive controlled-document review reminders.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.document_review.recipient_roles')->delete();
    }
};
