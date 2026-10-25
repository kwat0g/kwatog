<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEY = 'inventory.demand.projection_movement_types';

    private const LEGACY = ['material_issue', 'consume', 'production_issue'];

    private const EFFECTIVE = ['material_issue'];

    public function up(): void
    {
        $this->replaceIfUnchanged(self::LEGACY, self::EFFECTIVE);
    }

    public function down(): void
    {
        $this->replaceIfUnchanged(self::EFFECTIVE, self::LEGACY);
    }

    private function replaceIfUnchanged(array $from, array $to): void
    {
        $row = DB::table('settings')->where('key', self::KEY)->first();
        if ($row === null || json_decode((string) $row->value, true) !== $from) {
            return;
        }

        DB::table('settings')->where('key', self::KEY)->update([
            'value' => json_encode($to),
            'updated_at' => now(),
        ]);

        try {
            Cache::forget('settings:'.self::KEY);
        } catch (Throwable) {
            // Cache expiry is bounded; the database setting remains authoritative.
        }
    }
};
