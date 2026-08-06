<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'hr.performance.rating_scale',
            'value' => json_encode([
                ['value' => '1', 'label' => '1 - Unsatisfactory'],
                ['value' => '2', 'label' => '2 - Needs Improvement'],
                ['value' => '3', 'label' => '3 - Meets Expectations'],
                ['value' => '4', 'label' => '4 - Exceeds Expectations'],
                ['value' => '5', 'label' => '5 - Outstanding'],
            ]),
            'group' => 'hr',
            'label' => 'Performance Rating Scale',
            'description' => 'Score labels displayed in performance review forms and options.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'hr.performance.rating_scale')->delete();
    }
};
