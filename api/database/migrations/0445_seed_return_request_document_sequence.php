<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Register the `return_request` document sequence.
 *
 * ReturnRequestService::create() opens with
 * `$this->sequences->generate('return_request')`, but that key was never added
 * to documents.sequence_config — so DocumentSequenceService threw
 * "Unknown document type: return_request" and *every* attempt to create an RMA
 * failed with an unhandled HTTP 500. The whole Return Management module was
 * unreachable from the UI as a result.
 *
 * Format follows the monthly convention used by the other transactional
 * documents: RMA-YYYYMM-NNNN.
 */
return new class extends Migration {
    public function up(): void
    {
        $config = json_decode((string) DB::table('settings')
            ->where('key', 'documents.sequence_config')
            ->value('value'), true) ?? [];

        $config['return_request'] = ['prefix' => 'RMA', 'reset' => 'monthly', 'pad' => 4];

        DB::table('settings')->where('key', 'documents.sequence_config')
            ->update(['value' => json_encode($config), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $config = json_decode((string) DB::table('settings')
            ->where('key', 'documents.sequence_config')
            ->value('value'), true) ?? [];

        unset($config['return_request']);

        DB::table('settings')->where('key', 'documents.sequence_config')
            ->update(['value' => json_encode($config), 'updated_at' => now()]);
    }
};
