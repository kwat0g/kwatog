<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'payroll.bank_file.reference_prefix',
            'value' => json_encode('PAYROLL'),
            'group' => 'payroll',
            'label' => 'Payroll Bank Reference Prefix',
            'description' => 'Prefix used in payroll bank-file payment references.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'payroll.bank_file.reference_prefix')->delete();
    }
};
