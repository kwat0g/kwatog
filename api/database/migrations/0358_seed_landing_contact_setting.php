<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'company.sales_inbox_email',
            'value' => json_encode('sales@ogami.com.ph'),
            'group' => 'company',
            'label' => 'Sales Inbox Email',
            'description' => 'Mailbox receiving public quote requests from the landing page.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'company.sales_inbox_email')->delete();
    }
};
