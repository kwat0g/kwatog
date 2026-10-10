<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'accounting.accounts.maintenance_consumption_code',
            'value' => json_encode('6100'), 'group' => 'accounting',
            'label' => 'Maintenance Parts Expense Account',
            'description' => 'Expense account debited when spare parts are consumed by a maintenance work order.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep operator configuration when rolling back the code that consumes it.
        // A retained setting is harmless and makes a later reapply idempotent.
    }
};
