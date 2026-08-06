<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['landing.oem_partners', ['Toyota', 'Nissan', 'Honda', 'Suzuki', 'Yamaha'], 'Landing OEM Partners', 'Automotive OEM partner names displayed on the public landing page.'],
            ['landing.quality_methods', ['APQP', 'PPAP', 'MSA & SPC', 'Traceable lot control', '8D corrective action'], 'Landing Quality Methods', 'Quality methods displayed on the public landing page.'],
        ] as [$key, $values, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($values),
                'group' => 'landing',
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['landing.oem_partners', 'landing.quality_methods'])->delete();
    }
};
