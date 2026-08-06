<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $row = DB::table('settings')->where('key', 'landing.quality_policy')->first();
        $policy = $row ? (array) json_decode((string) $row->value, true) : [];
        $policy += [
            'commitment_body' => '{{company}} is committed to manufacturing products that consistently meet or exceed customer requirements and all applicable statutory and regulatory obligations.',
            'system_body' => 'Our Quality Management System is established, implemented, and continually improved in accordance with {{standard}}, reflecting our dedication to defect prevention, waste reduction, and consistent delivery.',
        ];

        if ($row) {
            DB::table('settings')->where('key', 'landing.quality_policy')->update([
                'value' => json_encode($policy),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $row = DB::table('settings')->where('key', 'landing.quality_policy')->first();
        if (! $row) {
            return;
        }

        $policy = (array) json_decode((string) $row->value, true);
        unset($policy['commitment_body'], $policy['system_body']);
        DB::table('settings')->where('key', 'landing.quality_policy')->update([
            'value' => json_encode($policy),
            'updated_at' => now(),
        ]);
    }
};
