<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'accounting.reporting_currency_code',
            'value' => json_encode('JPY'),
            'group' => 'accounting',
            'label' => 'Reporting Currency Code',
            'description' => 'Default currency used by translated parent financial statements.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'accounting.reporting_currency_code')->delete();
    }
};
