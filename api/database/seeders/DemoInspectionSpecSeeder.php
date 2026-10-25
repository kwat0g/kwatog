<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Support\ProductionAssertions;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Supplies an approved revision and checklist for every active demo product. */
class DemoInspectionSpecSeeder extends Seeder
{
    public function run(): void
    {
        ProductionAssertions::assertDemoSeedingAllowed();

        if (! config('app.seed_demo_data')) {
            throw new RuntimeException('Demo inspection specs require SEED_DEMO_DATA=true.');
        }

        $admin = User::query()->where('email', 'admin@ogami.test')->first();
        if (! $admin) {
            throw new RuntimeException('Demo inspection specs require admin@ogami.test.');
        }

        $params = [
            ['name' => 'Length', 'type' => 'dimensional', 'uom' => 'mm', 'nominal' => 100, 'min' => 99.5, 'max' => 100.5, 'critical' => true],
            ['name' => 'Width', 'type' => 'dimensional', 'uom' => 'mm', 'nominal' => 50, 'min' => 49.7, 'max' => 50.3, 'critical' => true],
            ['name' => 'Height', 'type' => 'dimensional', 'uom' => 'mm', 'nominal' => 30, 'min' => 29.8, 'max' => 30.2, 'critical' => false],
            ['name' => 'Weight', 'type' => 'dimensional', 'uom' => 'g', 'nominal' => 200, 'min' => 195, 'max' => 205, 'critical' => false],
            ['name' => 'Surface Finish', 'type' => 'visual', 'uom' => null, 'nominal' => null, 'min' => null, 'max' => null, 'critical' => false],
        ];

        $created = 0;
        foreach (DB::table('products')->where('is_active', true)->orderBy('id')->get() as $product) {
            if (DB::table('inspection_specs')->where('product_id', $product->id)->exists()) {
                continue;
            }

            $now = now();
            $specId = DB::table('inspection_specs')->insertGetId([
                'product_id' => $product->id,
                'version' => 1,
                'is_active' => true,
                'notes' => "Demo inspection spec for {$product->name}",
                'created_by' => $admin->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $revisionId = DB::table('inspection_spec_revisions')->insertGetId([
                'inspection_spec_id' => $specId,
                'version' => 1,
                'created_by' => $admin->id,
                'notes' => "Initial demo revision for {$product->name}",
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($params as $index => $param) {
                DB::table('inspection_spec_items')->insert([
                    'inspection_spec_id' => $specId,
                    'inspection_spec_revision_id' => $revisionId,
                    'parameter_name' => $param['name'],
                    'parameter_type' => $param['type'],
                    'acceptance_criterion' => $param['type'] === 'visual'
                        ? 'No cracks, damage, contamination, flash or short shots.'
                        : null,
                    'unit_of_measure' => $param['uom'],
                    'nominal_value' => $param['nominal'],
                    'tolerance_min' => $param['min'],
                    'tolerance_max' => $param['max'],
                    'is_critical' => $param['critical'],
                    'sort_order' => $index + 1,
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $created++;
        }

        $this->command?->info("[Inspection Specs] Ensured approved revisions for active demo products ({$created} new).");
    }
}
