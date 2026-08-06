<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Series E (Task E1) — seed company branding rows in the `settings` table
 * so every PDF letterhead reads from a single source. Idempotent: only
 * inserts keys that don't already exist (existing values are preserved).
 */
return new class extends Migration {
    public function up(): void
    {
        $defaults = [
            'company.legal_name'   => (string) env('COMPANY_LEGAL_NAME', ''),
            'company.address'      => (string) env('COMPANY_ADDRESS', ''),
            'company.phone'        => (string) env('COMPANY_PHONE', ''),
            'company.email'        => (string) env('COMPANY_EMAIL', ''),
            'company.tin'          => (string) env('COMPANY_TIN', ''),
            'company.vat_status'   => (string) env('COMPANY_VAT_STATUS', ''),
            'company.logo_path'    => (string) env('COMPANY_LOGO_PATH', ''),
            // Used when generating self-service URLs in QR codes.
            'company.public_url'   => (string) env('COMPANY_PUBLIC_URL', ''),
            // E1: shows on every PDF footer.
            'pdf.footer_disclaimer' => (string) env('PDF_FOOTER_DISCLAIMER', ''),
        ];

        $now = now();
        foreach ($defaults as $key => $value) {
            $exists = DB::table('settings')->where('key', $key)->exists();
            if ($exists) continue;

            DB::table('settings')->insert([
                'key'        => $key,
                'value'      => json_encode($value),
                'group'      => 'company',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('group', 'company')
            ->whereIn('key', [
                'company.legal_name', 'company.address', 'company.phone',
                'company.email', 'company.tin', 'company.vat_status',
                'company.logo_path', 'company.public_url',
                'pdf.footer_disclaimer',
            ])
            ->delete();
    }
};
