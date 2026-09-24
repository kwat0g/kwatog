<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * BUG A fix — Add Purchase Price Variance account and setting for existing databases.
     *
     * When a bill's price differs from the GRN's cost, we now post the variance to
     * account 5040 (Purchase Price Variance) instead of leaving a residual on GRNI.
     * This migration adds the account and its setting key for existing installations.
     */
    public function up(): void
    {
        // Add the PPV account if it doesn't exist
        $idsByCode = DB::table('accounts')->pluck('id', 'code');
        $parentId = $idsByCode['5000'] ?? null;

        if ($parentId && ! isset($idsByCode['5040'])) {
            DB::table('accounts')->insert([
                'code' => '5040',
                'name' => 'Purchase Price Variance',
                'type' => 'expense',
                'normal_balance' => 'debit',
                'parent_id' => $parentId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Add the setting if it doesn't exist (value must be JSON-encoded)
        DB::table('settings')->insertOrIgnore([
            'key' => 'accounting.accounts.purchase_price_variance_code',
            'value' => json_encode('5040'),
            'group' => 'accounting',
            'label' => 'Purchase Price Variance Account Code',
            'description' => 'Expense account for variances between bill and GRN costs in stock purchases.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Remove the setting
        DB::table('settings')
            ->where('key', 'accounting.accounts.purchase_price_variance_code')
            ->delete();

        // Remove the account (only if no journal entries reference it)
        $accountId = DB::table('accounts')
            ->where('code', '5040')
            ->value('id');

        if ($accountId) {
            $hasEntries = DB::table('journal_entry_lines')
                ->where('account_id', $accountId)
                ->exists();

            if (! $hasEntries) {
                DB::table('accounts')
                    ->where('id', $accountId)
                    ->delete();
            }
        }
    }
};
