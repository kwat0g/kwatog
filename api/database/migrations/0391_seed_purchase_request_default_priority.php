<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'purchasing.purchase_request.default_priority',
            'value' => json_encode('normal'),
            'group' => 'purchasing',
            'label' => 'Purchase Request Default Priority',
            'description' => 'Priority used when a purchase request omits an explicit priority.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'purchasing.purchase_request.default_priority')->delete();
    }
};
