<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'hr.probation.period_months',
            'value' => json_encode(6),
            'group' => 'hr',
            'label' => 'Probation Period (Months)',
            'description' => 'Configured probation period used by dashboard regularization alerts.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'hr.probation.period_months')->delete();
    }
};
