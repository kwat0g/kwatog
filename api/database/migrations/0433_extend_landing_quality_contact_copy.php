<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        if (! $row) return;
        $copy = (array) json_decode((string) $row->value, true);
        $copy += [
            'quality_title' => 'Quality you can audit, on every shipment.',
            'contact_title' => "Have a part in mind? Let's mold it.",
            'contact_intro' => 'Send us your drawing or your challenge. Our engineers will come back with tooling, tolerance, and timeline — and a clear path to your first certified shipment.',
            'contact_success_title' => 'Request received',
            'contact_success_body' => 'Thank you. Our engineers will review your part and reply within 1–2 business days.',
        ];
        DB::table('settings')->where('key', 'landing.section_copy')->update(['value' => json_encode($copy), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'landing.section_copy')->first();
        if (! $row) return;
        $copy = (array) json_decode((string) $row->value, true);
        foreach (['quality_title', 'contact_title', 'contact_intro', 'contact_success_title', 'contact_success_body'] as $key) unset($copy[$key]);
        DB::table('settings')->where('key', 'landing.section_copy')->update(['value' => json_encode($copy), 'updated_at' => now()]);
    }
};
