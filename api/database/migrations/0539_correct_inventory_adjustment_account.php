<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 0443 seeded the expense header 5000. PostingAccountResolver rejects
        // headers, so move only that legacy default to the posting leaf.
        DB::table('settings')
            ->where('key', 'accounting.accounts.inventory_adjustment_code')
            ->whereRaw('CAST("value" AS TEXT) = ?', ['"5000"'])
            ->update(['value' => '"5010"', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'accounting.accounts.inventory_adjustment_code')
            ->whereRaw('CAST("value" AS TEXT) = ?', ['"5010"'])
            ->update(['value' => '"5000"', 'updated_at' => now()]);
    }
};
