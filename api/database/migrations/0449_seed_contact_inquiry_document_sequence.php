<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers the `contact_inquiry` document sequence.
 *
 * `DocumentSequenceService::generate()` throws `InvalidArgumentException` on an
 * unknown type, and the config lives in the `documents.sequence_config`
 * *setting* rather than a table — the `document_sequences` row is created lazily
 * on first use. Without this key every contact-form submission would 500, which
 * is how the Return Management module shipped unreachable (see 0445).
 *
 * Leaves `quote_request` => QR in place: 0447 reshapes the table but any
 * historical rows keep their QR- numbers, and dropping the key would break
 * nothing yet gains nothing.
 */
return new class extends Migration {
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'documents.sequence_config')->first();
        if (! $row) {
            return;
        }

        $config = (array) json_decode((string) $row->value, true);
        $config['contact_inquiry'] = ['prefix' => 'INQ', 'reset' => 'monthly', 'pad' => 4];

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
        unset($config['contact_inquiry']);

        DB::table('settings')->where('key', 'documents.sequence_config')->update([
            'value' => json_encode($config),
            'updated_at' => now(),
        ]);
    }
};
