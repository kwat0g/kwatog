<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Add the read-side SLA for RMAs awaiting Quality inspection setup. */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'dashboard.chain_bottlenecks')->first();
        if (! $row) {
            return;
        }

        $current = json_decode((string) $row->value, true);
        if (! is_array($current)) {
            return;
        }

        $updated = $current + [
            'return_without_inspection' => [
                'label' => 'Return awaiting Quality inspection',
                'hours' => 4,
                'audience' => 'qc_inspector',
            ],
        ];

        if ($updated === $current) {
            return;
        }

        DB::table('settings')
            ->where('key', 'dashboard.chain_bottlenecks')
            ->update([
                'value' => json_encode($updated, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'dashboard.chain_bottlenecks')->first();
        if (! $row) {
            return;
        }

        $current = json_decode((string) $row->value, true);
        if (! is_array($current)) {
            return;
        }

        unset($current['return_without_inspection']);

        DB::table('settings')
            ->where('key', 'dashboard.chain_bottlenecks')
            ->update([
                'value' => json_encode($current, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
