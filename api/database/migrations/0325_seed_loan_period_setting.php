<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'loans.max_pay_periods', 'value' => json_encode(60),
            'group' => 'loans', 'label' => 'Maximum Loan Pay Periods',
            'description' => 'Maximum number of payroll periods allowed on a loan request.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'loans.max_pay_periods')->delete();
    }
};
