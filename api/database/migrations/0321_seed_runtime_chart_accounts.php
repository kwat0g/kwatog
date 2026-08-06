<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) return;

        $now = now();
        foreach ([
            ['1000', 'Assets', 'asset', 'debit', null],
            ['2000', 'Liabilities', 'liability', 'credit', null],
            ['1200', 'Inventory - Raw Materials', 'asset', 'debit', '1000'],
            ['1210', 'Inventory - Finished Goods', 'asset', 'debit', '1000'],
            ['1220', 'Inventory - Packaging', 'asset', 'debit', '1000'],
            ['1230', 'Inventory - Spare Parts', 'asset', 'debit', '1000'],
            ['2110', 'Goods Received Not Invoiced', 'liability', 'credit', '2000'],
        ] as [$code, $name, $type, $normal, $parentCode]) {
            $parentId = $parentCode ? DB::table('accounts')->where('code', $parentCode)->value('id') : null;
            DB::table('accounts')->updateOrInsert(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'normal_balance' => $normal, 'parent_id' => $parentId, 'is_active' => true, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void {}
};
