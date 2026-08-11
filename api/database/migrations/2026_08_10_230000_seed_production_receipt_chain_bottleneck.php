<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Add the read-side SLA for outputs missing a finished-goods receipt. */
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
            'production_output_without_receipt' => [
                'label' => 'Production output awaiting finished-goods receipt',
                'hours' => 4,
                'audience' => 'production_manager',
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

        unset($current['production_output_without_receipt']);

        DB::table('settings')
            ->where('key', 'dashboard.chain_bottlenecks')
            ->update([
                'value' => json_encode($current, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
