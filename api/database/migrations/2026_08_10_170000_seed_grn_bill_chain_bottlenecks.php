<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add read-side recovery policies for the accepted-GRN → supplier-bill chain.
 * Existing operator-specific policies win; this migration only supplies
 * missing keys and therefore does not reset live SLA choices.
 */
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

        $defaults = [
            'grn_accepted_without_bill' => [
                'label' => 'Accepted GRN awaiting supplier bill',
                'hours' => 4,
                'audience' => 'finance_officer',
            ],
            'bill_three_way_manual_review' => [
                'label' => 'Bill blocked by 3-way match',
                'hours' => 4,
                'audience' => 'finance_officer',
            ],
        ];

        $updated = $current + $defaults;
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

        unset($current['grn_accepted_without_bill'], $current['bill_three_way_manual_review']);

        DB::table('settings')
            ->where('key', 'dashboard.chain_bottlenecks')
            ->update([
                'value' => json_encode($current, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
