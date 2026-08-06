<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Point the public company location at the real plant.
 *
 * Migration 0427 seeded placeholder coordinates (14.3294, 120.9367 — a point
 * in Dasmariñas city proper, several km from the factory) and an earlier
 * environment seed left `company.address` as the string "Dasma". The landing
 * page location plate and every PDF letterhead read these settings, so both
 * now carry the actual registered address — Block 2, Lots 1 & 2, First Cavite
 * Industrial Estate, Barangay Langkaan 1, Dasmariñas, Cavite — and
 * coordinates inside the estate (14.2860, 120.9345).
 *
 * The `settings.value` column is jsonb, so matches and writes use the exact
 * jsonb::text form: numbers compare as `14.3294`, strings as `"Dasma"`.
 * Updates are guarded: only rows still holding the seeded placeholder values
 * are touched, so an operator who customised the settings keeps their values.
 */
return new class extends Migration {
    public function up(): void
    {
        $now = now();

        $updates = [
            ['key' => 'company.latitude', 'from' => '14.3294', 'value' => '14.2860'],
            ['key' => 'company.longitude', 'from' => '120.9367', 'value' => '120.9345'],
            ['key' => 'company.address', 'from' => '"Dasma"', 'value' => '"Block 2, Lots 1 & 2, First Cavite Industrial Estate (FCIE), Barangay Langkaan 1, Dasmariñas, Cavite, Philippines"'],
        ];

        foreach ($updates as $update) {
            DB::table('settings')
                ->where('key', $update['key'])
                ->whereRaw('value::text = ?', [$update['from']])
                ->update([
                    'value' => DB::raw("'{$update['value']}'::jsonb"),
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        $now = now();

        $reverts = [
            ['key' => 'company.latitude', 'to' => '14.3294', 'value' => '14.2860'],
            ['key' => 'company.longitude', 'to' => '120.9367', 'value' => '120.9345'],
            ['key' => 'company.address', 'to' => '"Dasma"', 'value' => '"Block 2, Lots 1 & 2, First Cavite Industrial Estate (FCIE), Barangay Langkaan 1, Dasmariñas, Cavite, Philippines"'],
        ];

        foreach ($reverts as $revert) {
            DB::table('settings')
                ->where('key', $revert['key'])
                ->whereRaw('value::text = ?', [$revert['value']])
                ->update([
                    'value' => DB::raw("'{$revert['to']}'::jsonb"),
                    'updated_at' => $now,
                ]);
        }
    }
};
