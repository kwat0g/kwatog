<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Register the system-generated, yearly customer-code sequence (CUS-YYYY-NNNN). */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'documents.sequence_config')->first();
        if (! $row) {
            return;
        }

        $config = (array) json_decode((string) $row->value, true);
        $config['customer'] = ['prefix' => 'CUS', 'reset' => 'yearly', 'pad' => 4];

        DB::table('settings')->where('key', 'documents.sequence_config')->update([
            'value' => json_encode($config),
            'updated_at' => now(),
        ]);

        // The code field existed before this became a system-generated value,
        // so make the existing customer master usable too. Continue from any
        // valid CUS number already present, then reserve that last number for
        // DocumentSequenceService so the next new record cannot collide.
        $year = (int) now()->format('Y');
        $prefix = "CUS-{$year}-";
        $last = 0;

        foreach (DB::table('customers')->where('code', 'like', $prefix.'%')->pluck('code') as $code) {
            if (preg_match('/^CUS-'.preg_quote((string) $year, '/').'-([0-9]{4})$/', (string) $code, $matches)) {
                $last = max($last, (int) $matches[1]);
            }
        }

        $missing = DB::table('customers')->whereNull('code')->orderBy('id')->get(['id']);
        foreach ($missing as $customer) {
            $last++;
            DB::table('customers')->where('id', $customer->id)->update([
                'code' => sprintf('CUS-%04d-%04d', $year, $last),
                'updated_at' => now(),
            ]);
        }

        $sequence = DB::table('document_sequences')
            ->where('document_type', 'customer')
            ->where('year', $year)
            ->where('month', 0)
            ->first();

        if ($sequence) {
            DB::table('document_sequences')->where('id', $sequence->id)->update([
                'last_number' => max((int) $sequence->last_number, $last),
            ]);
        } else {
            DB::table('document_sequences')->insert([
                'document_type' => 'customer',
                'prefix' => 'CUS',
                'year' => $year,
                'month' => 0,
                'last_number' => $last,
            ]);
        }
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'documents.sequence_config')->first();
        if (! $row) {
            return;
        }

        $config = (array) json_decode((string) $row->value, true);
        unset($config['customer']);

        DB::table('settings')->where('key', 'documents.sequence_config')->update([
            'value' => json_encode($config),
            'updated_at' => now(),
        ]);
    }
};
