<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['key' => 'company.latitude', 'value' => '14.3294', 'label' => 'Company latitude'],
            ['key' => 'company.longitude', 'value' => '120.9367', 'label' => 'Company longitude'],
        ] as $setting) {
            DB::table('settings')->insertOrIgnore([
                'key' => $setting['key'],
                'value' => $setting['value'],
                'group' => 'company',
                'label' => $setting['label'],
                'description' => 'Geographic coordinates used by public company location views.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['company.latitude', 'company.longitude'])->delete();
    }
};
