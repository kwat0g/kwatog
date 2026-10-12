<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 (PDF foundation) — branding settings behind the Atelier chrome.
 *
 * Two jobs, both default-only (existing operator values are never touched):
 *   1. Insert the three new keys (tagline, accent, terms) when missing.
 *   2. Backfill EMPTY strings for values the repository itself evidences:
 *      - company.legal_name from the repo master context (CLAUDE.md).
 *      - company.address from migration 0442's FCIE literal.
 *      - pdf.footer_disclaimer from migration 0405's generic text.
 *
 * Deliberately NOT seeded (no repo evidence — operator must enter):
 * company.tin/phone/email/logo_path, company.certification,
 * company.public_url, quality.coc.manager_name/role.
 */
return new class extends Migration
{
    public function up(): void
    {
        // NOTE: .env declares these keys as EMPTY strings, and env($k,
        // $default) only falls back when the variable is absent — so a
        // plain env() default never fires here. Empty env means "not set":
        // fall back to the repo-evidenced default. (An operator who truly
        // wants an empty value can blank it in the settings table / admin.)
        $envOr = static fn (string $key, string $default): string => trim((string) env($key)) !== '' ? trim((string) env($key)) : $default;

        $now = now();
        $inserts = [
            // Optional line under the company name on every letterhead.
            'company.tagline' => $envOr('COMPANY_TAGLINE', ''),
            // Hex accent for the PDF brand bar. Validated to ^#[0-9a-fA-F]{6}$
            // at render; anything else falls back to Atelier clay (#b4542a).
            'pdf.accent_color' => $envOr('PDF_ACCENT_COLOR', '#b4542a'),
            // Standard terms & conditions printed by the notes block.
            'pdf.terms_conditions' => $envOr('PDF_TERMS_CONDITIONS', ''),
        ];

        foreach ($inserts as $key => $value) {
            $exists = DB::table('settings')->where('key', $key)->exists();
            if ($exists) {
                continue;
            }

            DB::table('settings')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'group' => 'company',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $backfills = [
            'company.legal_name' => $envOr('COMPANY_LEGAL_NAME', 'Philippine Ogami Corporation'),
            'company.address' => $envOr('COMPANY_ADDRESS', 'Block 2, Lots 1 & 2, First Cavite Industrial Estate (FCIE), Barangay Langkaan 1, Dasmariñas, Cavite, Philippines'),
            'pdf.footer_disclaimer' => $envOr('PDF_FOOTER_DISCLAIMER', 'This document was system-generated and does not require a wet signature unless indicated.'),
        ];

        foreach ($backfills as $key => $value) {
            if (trim($value) === '') {
                continue;
            }
            $row = DB::table('settings')->where('key', $key)->first();
            if ($row === null) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => json_encode($value),
                    'group' => 'company',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } elseif (json_decode((string) $row->value, true) === '') {
                DB::table('settings')->where('key', $key)->update([
                    'value' => json_encode($value),
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Only removes the keys this migration introduced. Backfilled values
        // are data corrections on pre-existing keys — down() leaves them.
        DB::table('settings')
            ->where('group', 'company')
            ->whereIn('key', [
                'company.tagline', 'pdf.accent_color', 'pdf.terms_conditions',
            ])
            ->delete();
    }
};
