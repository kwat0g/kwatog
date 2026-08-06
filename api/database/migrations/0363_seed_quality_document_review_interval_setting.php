<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.document.max_review_interval_months', 'value' => json_encode(120),
            'group' => 'quality', 'label' => 'Maximum Document Review Interval (Months)',
            'description' => 'Maximum permitted interval between controlled-document reviews.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.document.max_review_interval_months')->delete();
    }
};
