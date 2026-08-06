<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'payroll.thirteenth_month.default_pay_day'],
            [
                'value' => '15',
                'group' => 'payroll',
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'payroll.thirteenth_month.default_pay_day')->delete();
    }
};
