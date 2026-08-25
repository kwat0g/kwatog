<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\DefectParetoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityAnalyticsBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_pareto_uses_one_completed_measurement_denominator_and_excludes_cancelled_rows(): void
    {
        $product = Product::factory()->create();

        $this->inspectionWithDefects($product, InspectionStatus::Passed, [
            'Surface scratch' => 2,
            'Burr' => 1,
        ]);
        $this->inspectionWithDefects($product, InspectionStatus::Failed, [
            'Surface scratch' => 1,
        ]);
        $cancelled = $this->inspectionWithDefects($product, InspectionStatus::Cancelled, [
            'Cancelled defect' => 1,
        ]);

        $result = app(DefectParetoService::class)->run([
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $this->assertSame(4, $result['total_defects']);
        $this->assertSame([
            'Surface scratch',
            'Burr',
        ], array_column($result['rows'], 'parameter_name'));
        $this->assertSame(75.0, $result['rows'][0]['percentage']);
        $this->assertSame(100.0, $result['rows'][1]['cumulative_percentage']);

        $drill = app(DefectParetoService::class)->inspectionsWithDefect('Cancelled defect', [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);

        $this->assertSame([], $drill);
        $this->assertDatabaseHas('inspections', ['id' => $cancelled->id, 'status' => InspectionStatus::Cancelled->value]);
    }

    public function test_invalid_product_filter_is_rejected_instead_of_becoming_unfiltered(): void
    {
        $viewer = $this->userWithPermissions('quality-analytics-viewer', ['quality.view']);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/quality/analytics/inspection-summary?product_id=expired-product-hash')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_capability_rejects_a_spec_item_from_another_product(): void
    {
        $viewer = $this->userWithPermissions('quality-capability-viewer', ['quality.inspections.view']);
        $firstProduct = Product::factory()->create();
        $secondProduct = Product::factory()->create();
        $spec = InspectionSpec::create([
            'product_id' => $firstProduct->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $viewer->id,
        ]);
        $item = InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Diameter',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'tolerance_min' => '9.0000',
            'tolerance_max' => '11.0000',
            'is_critical' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/quality/spc/capability', [
                'product_id' => $secondProduct->hash_id,
                'spec_item_id' => $item->hash_id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'quality_capability_spec_product_mismatch')
            ->assertJsonValidationErrors(['spec_item_id']);
    }

    public function test_capability_reports_insufficient_completed_samples_as_a_typed_422(): void
    {
        $viewer = $this->userWithPermissions('quality-capability-empty', ['quality.inspections.view']);
        $product = Product::factory()->create();
        $spec = InspectionSpec::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $viewer->id,
        ]);
        $item = InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Diameter',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'tolerance_min' => '9.0000',
            'tolerance_max' => '11.0000',
            'is_critical' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/quality/spc/capability', [
                'product_id' => $product->hash_id,
                'spec_item_id' => $item->hash_id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'quality_capability_insufficient_samples')
            ->assertJsonValidationErrors(['spec_item_id']);
    }

    public function test_capability_uses_only_passed_and_failed_inspection_measurements(): void
    {
        $viewer = $this->userWithPermissions('quality-capability-terminal-only', ['quality.inspections.view']);
        $product = Product::factory()->create();
        $spec = InspectionSpec::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $viewer->id,
        ]);
        $item = InspectionSpecItem::create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Diameter',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'tolerance_min' => '9.0000',
            'tolerance_max' => '11.0000',
            'is_critical' => true,
            'sort_order' => 0,
        ]);

        foreach ([
            InspectionStatus::Passed,
            InspectionStatus::Passed,
            InspectionStatus::Passed,
            InspectionStatus::Failed,
            InspectionStatus::Failed,
            InspectionStatus::Draft,
            InspectionStatus::InProgress,
            InspectionStatus::Cancelled,
        ] as $index => $status) {
            $inspection = Inspection::create([
                'inspection_number' => 'CAP-'.strtoupper(str_replace('.', '', uniqid('', true))),
                'stage' => InspectionStage::Outgoing,
                'status' => $status,
                'product_id' => $product->id,
                'inspection_spec_id' => $spec->id,
                'batch_quantity' => 10,
                'sample_size' => 1,
                'completed_at' => now(),
            ]);
            InspectionMeasurement::create([
                'inspection_id' => $inspection->id,
                'inspection_spec_item_id' => $item->id,
                'sample_index' => $index + 1,
                'parameter_name' => $item->parameter_name,
                'parameter_type' => 'dimensional',
                'tolerance_min' => '9.0000',
                'tolerance_max' => '11.0000',
                'measured_value' => '10.0000',
                'is_critical' => true,
                'is_pass' => true,
            ]);
        }

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/quality/spc/capability', [
                'product_id' => $product->hash_id,
                'spec_item_id' => $item->hash_id,
            ])
            ->assertOk()
            ->assertJsonPath('data.sample_count', 5);
    }

    public function test_capability_route_requires_inspection_view_permission(): void
    {
        $viewer = $this->userWithPermissions('quality-dashboard-only', ['quality.view']);

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/v1/quality/spc/capability', [
                'product_id' => 'invalid',
                'spec_item_id' => 'invalid',
            ])
            ->assertForbidden();
    }

    private function inspectionWithDefects(Product $product, InspectionStatus $status, array $parameters): Inspection
    {
        $inspection = Inspection::create([
            'inspection_number' => 'AN-'.strtoupper(str_replace('.', '', uniqid('', true))),
            'stage' => InspectionStage::Outgoing,
            'status' => $status,
            'product_id' => $product->id,
            'batch_quantity' => 10,
            'sample_size' => 10,
            'completed_at' => now(),
        ]);

        $sample = 1;
        foreach ($parameters as $parameter => $count) {
            for ($i = 0; $i < $count; $i++) {
                InspectionMeasurement::create([
                    'inspection_id' => $inspection->id,
                    'sample_index' => $sample++,
                    'parameter_name' => $parameter,
                    'parameter_type' => 'visual',
                    'is_critical' => false,
                    'is_pass' => false,
                ]);
            }
        }

        return $inspection;
    }

    private function userWithPermissions(string $slug, array $permissions): User
    {
        $role = Role::create(['name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug]);
        $permissionIds = [];
        foreach ($permissions as $permissionSlug) {
            $permissionIds[] = Permission::firstOrCreate(
                ['slug' => $permissionSlug],
                ['name' => $permissionSlug, 'module' => 'quality'],
            )->id;
        }
        $role->permissions()->sync($permissionIds);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
