<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $settings = [
            ['landing.trust_points', ['IATF 16949 Certified', '5 OEM partners', '≤10 PPM defect target'], 'Landing Trust Points', 'Trust badges displayed in the public landing hero.'],
            ['landing.philippines_points', [
                ['value' => '200+', 'label' => 'Skilled Filipino engineers, operators, and quality inspectors'],
                ['value' => 'FCIE', 'label' => 'First Cavite Industrial Estate — Dasmariñas, Cavite'],
                ['value' => '100%', 'label' => 'Global automotive standards, delivered locally'],
            ], 'Landing Filipino-made Proof Points', 'Proof points displayed in the Filipino-made section.'],
            ['landing.stats', [
                ['id' => 'employees', 'value' => 200, 'suffix' => '+', 'label' => 'Skilled Filipino employees'],
                ['id' => 'oem', 'value' => 5, 'label' => 'Global OEM partners'],
                ['id' => 'ppm', 'value' => 10, 'prefix' => '≤', 'suffix' => ' PPM', 'label' => 'Defect rate target'],
                ['id' => 'otd', 'value' => 99.8, 'suffix' => '%', 'decimals' => 1, 'label' => 'On-time delivery'],
            ], 'Landing Proof Statistics', 'Proof statistics displayed on the public landing page.'],
        ];

        foreach ($settings as [$key, $value, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
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
        DB::table('settings')->whereIn('key', [
            'landing.trust_points', 'landing.philippines_points', 'landing.stats',
        ])->delete();
    }
};
