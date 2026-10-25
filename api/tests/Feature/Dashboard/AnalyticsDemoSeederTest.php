<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Models\KpiSnapshot;
use App\Modules\DSS\Services\DssParameterService;
use Database\Seeders\AnalyticsDemoSeeder;
use Database\Seeders\KpiDefinitionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_computes_kpis_from_source_rows_and_is_repeatable(): void
    {
        Carbon::setTestNow('2026-10-24 12:00:00');
        config(['app.seed_demo_data' => true]);

        try {
            $this->seed([SettingsSeeder::class, KpiDefinitionSeeder::class]);
            app(DssParameterService::class)->set('global', 'dss.engine.enabled', false);
            User::factory()->create(['email' => 'admin@ogami.test']);

            $product = Product::query()->create([
                'part_number' => 'ANALYTICS-KPI-001',
                'name' => 'Analytics KPI Product',
                'unit_of_measure' => 'pcs',
                'standard_cost' => 10,
                'is_active' => true,
            ]);

            DB::table('inspections')->insert([
                [
                    'inspection_number' => 'ANALYTICS-KPI-QC-001', 'stage' => 'outgoing', 'status' => 'passed',
                    'product_id' => $product->id, 'batch_quantity' => 100, 'sample_size' => 100,
                    'defect_count' => 2, 'completed_at' => '2026-10-10 10:00:00',
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'inspection_number' => 'ANALYTICS-KPI-QC-002', 'stage' => 'outgoing', 'status' => 'failed',
                    'product_id' => $product->id, 'batch_quantity' => 100, 'sample_size' => 100,
                    'defect_count' => 3, 'completed_at' => '2026-10-11 10:00:00',
                    'created_at' => now(), 'updated_at' => now(),
                ],
            ]);

            $seeder = new AnalyticsDemoSeeder;
            $seeder->run();

            $spec = DB::table('inspection_specs')->where('product_id', $product->id)->first();
            $this->assertNotNull($spec, 'Every active demo product needs an inspection spec.');
            $this->assertDatabaseHas('inspection_spec_revisions', [
                'inspection_spec_id' => $spec->id,
                'version' => 1,
            ]);
            $this->assertGreaterThanOrEqual(5, DB::table('inspection_spec_items')
                ->where('inspection_spec_id', $spec->id)
                ->count());

            $dppm = KpiDefinition::query()->where('code', 'dppm')->firstOrFail();
            $snapshot = KpiSnapshot::query()
                ->where('definition_id', $dppm->id)
                ->where('period_year', 2026)
                ->where('period_month', 10)
                ->firstOrFail();
            $this->assertSame('25000.0000', (string) $snapshot->actual_value);

            $seeder->run();

            $this->assertSame(1, DB::table('inspection_specs')->where('product_id', $product->id)->count());
            $this->assertSame(1, KpiSnapshot::query()
                ->where('definition_id', $dppm->id)
                ->where('period_year', 2026)
                ->where('period_month', 10)
                ->count());
            $this->assertSame('25000.0000', (string) KpiSnapshot::query()
                ->where('definition_id', $dppm->id)
                ->where('period_year', 2026)
                ->where('period_month', 10)
                ->value('actual_value'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
