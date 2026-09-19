<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $parentId = DB::table('accounts')->where('code', '2000')->value('id');
        DB::table('accounts')->insertOrIgnore([
            'code' => '2120',
            'name' => 'Landed Cost Clearing',
            'type' => 'liability',
            'normal_balance' => 'credit',
            'parent_id' => $parentId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $accountId = DB::table('accounts')->where('code', '2120')->value('id');
        if ($accountId === null) {
            return;
        }

        DB::table('accounts')
            ->where('id', $accountId)
            ->whereNotExists(fn ($query) => $query->select(DB::raw('1'))
                ->from('journal_entry_lines')
                ->whereColumn('journal_entry_lines.account_id', 'accounts.id'))
            ->delete();
    }
};
