<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('dss_recommendations')
            ->whereIn('status', ['open', 'snoozed'])
            ->whereNull('expires_at')
            ->update(['expires_at' => DB::raw("updated_at + INTERVAL '26 hours'")]);
    }

    public function down(): void
    {
        // Backfilled expiries cannot be distinguished safely from explicit validity windows.
    }
};
