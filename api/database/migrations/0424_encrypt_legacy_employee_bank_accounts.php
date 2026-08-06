<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('employees')
            ->select(['id', 'bank_account_no'])
            ->whereNotNull('bank_account_no')
            ->orderBy('id')
            ->get()
            ->each(function (object $employee): void {
                $raw = (string) $employee->bank_account_no;
                // Legacy seed/import rows were plain numeric account values;
                // encrypted payloads are JSON/base64 and must remain intact.
                if ($raw !== '' && ctype_digit($raw)) {
                    DB::table('employees')->where('id', $employee->id)->update([
                        'bank_account_no' => Crypt::encryptString($raw),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Encrypted values cannot be safely restored to plaintext.
    }
};
