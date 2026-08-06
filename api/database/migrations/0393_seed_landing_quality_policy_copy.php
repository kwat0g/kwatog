<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'landing.quality_policy',
            'value' => json_encode([
                'standard' => 'IATF 16949',
                'certification_title' => 'IATF 16949:2016 Certified',
                'certification_body' => 'Our quality management system is certified for automotive production — audited, maintained, and continuously improved.',
                'conformance_title' => 'Every shipment ships with a Certificate of Conformance',
                'conformance_body' => 'built from real inspection data — outgoing parts are sampled at AQL 0.65 Level II and measured against your critical-dimension tolerances, with full traceability from resin lot to delivery.',
            ]),
            'group' => 'landing',
            'label' => 'Landing Quality Policy Copy',
            'description' => 'Certification and conformance copy displayed on the public landing page.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'landing.quality_policy')->delete();
    }
};
