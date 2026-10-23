<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        // Upgrade only the legacy default; preserve any operator-selected code.
        DB::table('settings')
            ->where('key', 'accounting.accounts.discount_code')
            ->whereRaw('value::text = ?', [json_encode('4010', JSON_THROW_ON_ERROR)])
            ->update(['value' => json_encode('4040'), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // This setting is live operator data; rollback leaves it untouched.
    }
};
