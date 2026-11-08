<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Support\E2EEnvironment;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Small, explicit master-data additions required by UI-only E2E journeys.
 * This seeder may create inventory identities, but never balances or process transactions.
 */
final class E2EReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        E2EEnvironment::assertEnabled();

        // Active BOM-backed products need their matching finished-good item
        // identities before production journeys can record stock movements.
        // The seeder creates master data only; stock is still created through
        // the Warehouse UI by the owning role in each journey.
        $this->call(ManufacturedProductInventorySeeder::class);

        $product = Product::query()->where('part_number', 'WB-001')->where('is_active', true)->first();
        $qualityInspector = User::query()->whereHas('role', fn ($role) => $role->where('slug', 'qc_inspector'))
            ->where('is_active', true)->orderBy('id')->first();
        if (! $product || ! $qualityInspector) {
            throw new RuntimeException('E2E production reference data requires active WB-001 and qc_inspector baseline records.');
        }

        DB::transaction(function () use ($product, $qualityInspector): void {
            $now = now();
            $specId = DB::table('inspection_specs')->where('product_id', $product->id)->value('id');
            if ($specId !== null) {
                $hasCurrentItems = DB::table('inspection_spec_revisions as revision')
                    ->join('inspection_spec_items as item', 'item.inspection_spec_revision_id', '=', 'revision.id')
                    ->where('revision.inspection_spec_id', $specId)
                    ->where('revision.version', DB::table('inspection_specs')->where('id', $specId)->value('version'))
                    ->exists();
                if ($hasCurrentItems) {
                    return;
                }

                throw new RuntimeException('E2E WB-001 already has an inspection spec without current revision items; refusing to rewrite reference history.');
            }

            $specId = DB::table('inspection_specs')->insertGetId([
                'product_id' => $product->id,
                'version' => 1,
                'is_active' => true,
                'notes' => 'E2E baseline visual acceptance fixture; not a production specification.',
                'created_by' => $qualityInspector->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $revisionId = DB::table('inspection_spec_revisions')->insertGetId([
                'inspection_spec_id' => $specId,
                'version' => 1,
                'created_by' => $qualityInspector->id,
                'notes' => 'E2E baseline visual acceptance fixture.',
                'capture_method' => 'measurement',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('inspection_spec_items')->insert([
                'inspection_spec_id' => $specId,
                'inspection_spec_revision_id' => $revisionId,
                'parameter_name' => 'Visual appearance',
                'parameter_type' => 'visual',
                'acceptance_criterion' => 'No visible cracks, damage, contamination, flash or short shots.',
                'unit_of_measure' => null,
                'nominal_value' => null,
                'tolerance_min' => null,
                'tolerance_max' => null,
                'is_critical' => false,
                'sort_order' => 1,
                'notes' => 'E2E-only baseline fixture; never used as a production control plan.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $this->command?->info('Seeded E2E-only WB-001 inspection reference data; no inventory or process records were created.');
    }
}
