<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'documents.sequence_config')->first();
        if (! $row) {
            return;
        }
        $config = (array) json_decode((string) $row->value, true);
        $config['rfq'] = ['prefix' => 'RFQ', 'reset' => 'monthly', 'pad' => 4];
        DB::table('settings')->where('key', 'documents.sequence_config')->update([
            'value' => json_encode($config),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'documents.sequence_config')->first();
        if (! $row) {
            return;
        }
        $config = (array) json_decode((string) $row->value, true);
        unset($config['rfq']);
        DB::table('settings')->where('key', 'documents.sequence_config')->update([
            'value' => json_encode($config),
            'updated_at' => now(),
        ]);
    }
};
