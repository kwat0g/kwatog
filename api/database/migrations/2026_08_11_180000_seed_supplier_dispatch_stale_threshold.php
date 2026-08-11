<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supplier dispatch recovery is a business policy, not a PHP constant. Keep
 * the initial value in the settings store so operators can tune it from the
 * admin settings endpoint without changing or redeploying application code.
 */
return new class extends Migration
{
    private const KEY = 'purchasing.supplier_dispatch.stale_after_minutes';

    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => self::KEY,
            'value' => json_encode(10),
            'group' => 'purchasing',
            'label' => 'Supplier Dispatch Stale Age (minutes)',
            'description' => 'Minutes after which an unconfirmed supplier dispatch may be reclaimed by the recovery job.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
