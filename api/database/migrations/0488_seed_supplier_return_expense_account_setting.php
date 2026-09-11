<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'accounting.accounts.purchase_return_expense_code'],
            [
                // SettingsService::get() json_decodes every value, and
                // requiredString() rejects a non-string. A raw '5010' decodes
                // to int 5010 and silently fails the "is a string" check, so
                // the value must be JSON-encoded like every other settings row.
                'value'       => json_encode('5010'),
                'group'       => 'accounting',
                'label'       => 'Purchase Return Expense Account Code',
                'description' => 'Expense account used to post supplier/purchase return credit notes that have no source bill line.',
                'updated_at'  => now(),
                'created_at'  => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'accounting.accounts.purchase_return_expense_code')->delete();
    }
};
