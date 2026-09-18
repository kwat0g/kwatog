<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts') || ! Schema::hasTable('settings')) {
            return;
        }

        $parentId = DB::table('accounts')->where('code', '4000')->value('id');
        if ($parentId !== null) {
            DB::table('accounts')->updateOrInsert(
                ['code' => '4040'],
                [
                    'name' => 'Sales Discounts',
                    'type' => 'revenue',
                    'normal_balance' => 'debit',
                    'parent_id' => $parentId,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $setting = DB::table('settings')->where('key', 'accounting.accounts.discount_code')->first();
            if ($setting !== null && json_decode((string) $setting->value, true) === '4010') {
                DB::table('settings')
                    ->where('key', 'accounting.accounts.discount_code')
                    ->update(['value' => json_encode('4040'), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')
            ->where('key', 'accounting.accounts.discount_code')
            ->where('value', json_encode('4040'))
            ->update(['value' => json_encode('4010'), 'updated_at' => now()]);

        if (Schema::hasTable('accounts') && Schema::hasTable('journal_entry_lines')) {
            DB::table('accounts')
                ->where('code', '4040')
                ->whereNotExists(fn ($query) => $query
                    ->select(DB::raw(1))
                    ->from('journal_entry_lines')
                    ->whereColumn('journal_entry_lines.account_id', 'accounts.id'))
                ->delete();
        }
    }
};
