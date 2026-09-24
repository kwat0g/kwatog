<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'landing.pii_retention_months',
            'value' => json_encode(12, JSON_THROW_ON_ERROR),
            'group' => 'landing',
            'label' => 'Landing Form PII Retention (months)',
            'description' => 'Retention period for closed contact inquiries and unsubscribed newsletter records.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'landing.pii_retention_months')->delete();
    }
};
