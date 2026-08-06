<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'landing.part_specs',
            'value' => json_encode([
                ['id' => 'wiper-bushing', 'name' => 'Wiper bushing', 'material' => 'POM resin', 'tolerance' => '±0.02 mm', 'application' => 'Steering & wiper linkages', 'feature' => 'Ø 12.0 bore'],
                ['id' => 'pivot-cap', 'name' => 'Pivot cap', 'material' => 'PA66 resin', 'tolerance' => '±0.03 mm', 'application' => 'Hood & engine covers', 'feature' => 'Domed shell'],
                ['id' => 'filler-cap', 'name' => 'Oil filler cap', 'material' => 'PA66 resin', 'tolerance' => '±0.05 mm', 'application' => 'Fuel & fluid systems', 'feature' => 'Sealed deck'],
                ['id' => 'spacer-collar', 'name' => 'Spacer collar', 'material' => 'POM resin', 'tolerance' => '±0.02 mm', 'application' => 'Bearing & shaft assemblies', 'feature' => 'Ø 10.0 bore'],
            ]),
            'group' => 'landing', 'label' => 'Landing 3D Part Specifications',
            'description' => 'Customer-facing material, tolerance, application, and feature data for the landing part showcase.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'landing.part_specs')->delete();
    }
};
