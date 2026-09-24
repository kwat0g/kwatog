<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 5000 is the COGS header and cannot receive postings. Only replace the
        // shipped default; leave a business owner's different mapping intact.
        $row = DB::table('settings')
            ->where('key', 'accounting.default_expense_account_code')
            ->first(['value']);

        if ($row && json_decode((string) $row->value, true) === '5000') {
            DB::table('settings')
                ->where('key', 'accounting.default_expense_account_code')
                ->update(['value' => json_encode('5010'), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Do not restore 5000: it is a header account and would break posting.
    }
};
