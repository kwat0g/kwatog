<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\AccountingPeriodSeeder;
use Database\Seeders\DemoInspectionEvidenceSeeder;
use Database\Seeders\DemoItemQualityPlanSeeder;
use Database\Seeders\MachineSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\Sprint8DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeedSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_quality_plan_seed_covers_missing_eligible_items_idempotently(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        User::factory()->create(['role_id' => Role::query()->where('slug', 'system_admin')->value('id')]);
        $item = Item::factory()->create(['code' => 'RM-DEMO-QUALITY-001', 'item_type' => 'raw_material']);
        config(['app.seed_demo_data' => true, 'inspection.checklist_capture_enabled' => true]);

        $this->seed(DemoItemQualityPlanSeeder::class);
        $this->seed(DemoItemQualityPlanSeeder::class);

        $this->assertSame(1, DB::table('item_quality_plans')->where('item_id', $item->id)->count());
        $this->assertDatabaseHas('item_quality_plans', [
            'item_id' => $item->id,
            'stage' => 'incoming',
            'sampling_method' => 'fixed',
            'fixed_sample_size' => 3,
            'is_active' => true,
        ]);
        $plan = DB::table('item_quality_plans')->where('item_id', $item->id)->first();
        $parameters = json_decode($plan->parameters, true);
        $this->assertCount(count($parameters), array_filter($parameters, fn ($parameter) => trim((string) ($parameter['acceptance_criterion'] ?? '')) !== ''));
    }

    public function test_accounting_period_seed_is_idempotent_and_preserves_closed_periods(): void
    {
        Carbon::setTestNow('2026-10-22 12:00:00');

        try {
            $this->seed(AccountingPeriodSeeder::class);
            $this->seed(AccountingPeriodSeeder::class);

            $this->assertDatabaseHas('accounting_periods', [
                'year' => 2026,
                'month' => 10,
                'status' => 'open',
            ]);
            $this->assertSame(1, DB::table('accounting_periods')->count());

            DB::table('accounting_periods')->where('year', 2026)->where('month', 10)->update(['status' => 'closed']);
            $this->seed(AccountingPeriodSeeder::class);

            $this->assertDatabaseHas('accounting_periods', [
                'year' => 2026,
                'month' => 10,
                'status' => 'closed',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_demo_qc_seed_completes_only_missing_measurement_evidence_idempotently(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create([
            'role_id' => Role::query()->value('id'),
        ]);
        $product = Product::create([
            'part_number' => 'DEMO-QC-001',
            'name' => 'Demo QC Product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => 10,
            'is_active' => true,
        ]);

        $specId = DB::table('inspection_specs')->insertGetId([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $revisionId = DB::table('inspection_spec_revisions')->insertGetId([
            'inspection_spec_id' => $specId,
            'version' => 1,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $itemId = DB::table('inspection_spec_items')->insertGetId([
            'inspection_spec_id' => $specId,
            'inspection_spec_revision_id' => $revisionId,
            'parameter_name' => 'Outer diameter',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '5.0000',
            'tolerance_min' => '4.0000',
            'tolerance_max' => '6.0000',
            'acceptance_criterion' => 'Within 4 to 6 mm.',
            'is_critical' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inspectionId = DB::table('inspections')->insertGetId([
            'inspection_number' => 'QC-DEMO-EVIDENCE-001',
            'stage' => 'outgoing',
            'status' => 'passed',
            'product_id' => $product->id,
            'inspection_spec_id' => $specId,
            'inspection_spec_revision_id' => $revisionId,
            'entity_type' => 'work_order',
            'batch_quantity' => 2,
            'sample_size' => 2,
            'inspected_quantity' => 2,
            'accept_count' => 0,
            'reject_count' => 1,
            'defect_count' => 0,
            'sample_defect_count' => 0,
            'inspector_id' => $user->id,
            'inspection_mode' => 'lot_checklist',
            'critical_defect' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inspection_measurements')->insert([
            'inspection_id' => $inspectionId,
            'inspection_spec_item_id' => $itemId,
            'sample_index' => 1,
            'parameter_name' => 'Outer diameter',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '5.0000',
            'tolerance_min' => '4.0000',
            'tolerance_max' => '6.0000',
            'measured_value' => null,
            'is_critical' => true,
            'is_pass' => null,
            'item_result' => null,
            'acceptance_criterion' => 'Within 4 to 6 mm.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config(['app.seed_demo_data' => true]);

        Artisan::call('demo:verify');
        $this->assertStringContainsString('QC-DEMO-EVIDENCE-001', Artisan::output());

        $this->seed(DemoInspectionEvidenceSeeder::class);
        $this->seed(DemoInspectionEvidenceSeeder::class);

        Artisan::call('demo:verify');
        $this->assertStringNotContainsString('QC-DEMO-EVIDENCE-001', Artisan::output());

        $this->assertSame(2, DB::table('inspection_measurements')->where('inspection_id', $inspectionId)->count());
        $this->assertSame(2, DB::table('inspection_measurements')->where('inspection_id', $inspectionId)->distinct('sample_index')->count('sample_index'));
        $this->assertSame(0, DB::table('inspection_measurements')->where('inspection_id', $inspectionId)->whereNull('is_pass')->count());
        $this->assertSame(0, DB::table('inspection_measurements')->where('inspection_id', $inspectionId)->whereNull('item_result')->count());
        $this->assertDatabaseHas('inspections', ['id' => $inspectionId, 'inspected_quantity' => 2]);
    }

    public function test_sprint_eight_seeder_links_existing_seeded_machine_assets(): void
    {
        $this->seed(RolePermissionSeeder::class);
        User::factory()->create([
            'role_id' => Role::query()->value('id'),
        ]);
        $this->seed(MachineSeeder::class);
        DB::table('assets')->insert([
            'asset_code' => 'AST-MCH-0001',
            'name' => 'Toshiba EC100SX (IMM-01)',
            'category' => 'machine',
            'acquisition_date' => today()->toDateString(),
            'acquisition_cost' => 1000000,
            'useful_life_years' => 10,
            'salvage_value' => 10000,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(Sprint8DemoSeeder::class);

        $this->assertDatabaseHas('machines', ['machine_code' => 'IMM-01', 'asset_id' => DB::table('assets')->where('asset_code', 'AST-MCH-0001')->value('id')]);
    }
}
