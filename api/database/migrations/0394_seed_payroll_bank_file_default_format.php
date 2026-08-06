<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'payroll.bank_file.default_format',
            'value' => json_encode('generic'),
            'group' => 'payroll',
            'label' => 'Default Payroll Bank File Format',
            'description' => 'Bank file format used when payroll disbursement format is not explicitly selected.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'payroll.bank_file.default_format')->delete();
    }
};
