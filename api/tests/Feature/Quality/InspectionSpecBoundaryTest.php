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
use App\Modules\Quality\Models\InspectionSpecRevision;
use App\Modules\Quality\Services\SpcService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectionSpecBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->userWithPermissions('inspection-spec-manager', [
            'quality.specs.view',
            'quality.specs.manage',
        ]);
        $this->viewer = $this->userWithPermissions('inspection-spec-viewer', [
            'quality.specs.view',
        ]);
    }

    public function test_archive_and_restore_are_symmetric_and_soft_deleted_rows_bind(): void
    {
        $product = Product::factory()->create();
        $spec = $this->createSpec($product, $this->manager);

        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/v1/quality/inspection-specs/{$spec->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $archived = InspectionSpec::withTrashed()->findOrFail($spec->id);
        $this->assertFalse($archived->is_active);
        $this->assertNotNull($archived->deleted_at);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/quality/inspection-specs/{$spec->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/quality/inspection-specs/{$spec->hash_id}/restore")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('inspection_specs', [
            'id' => $spec->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
    }

    public function test_search_view_permission_and_validation_are_enforced_at_the_http_boundary(): void
    {
        $matching = Product::factory()->create([
            'part_number' => 'SEARCH-100',
            'name' => 'Matched part',
        ]);
        $other = Product::factory()->create([
            'part_number' => 'OTHER-200',
            'name' => 'Other part',
        ]);

        $this->createSpec($matching, $this->manager);
        $this->createSpec($other, $this->manager);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/quality/inspection-specs?search=search-100&trashed=with')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.part_number', 'SEARCH-100');

        // Searching the product NAME must narrow the same way it does by part
        // number, and a term matching neither must return nothing.
        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/quality/inspection-specs?search=Matched&trashed=with')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.part_number', 'SEARCH-100');

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/quality/inspection-specs?search=no-such-product&trashed=with')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->viewer, 'sanctum')
            ->getJson('/api/v1/quality/inspection-specs')
            ->assertOk();

        $this->actingAs($this->viewer, 'sanctum')
            ->postJson('/api/v1/quality/inspection-specs', $this->payload($matching, [
                'tolerance_min' => '2.0000',
                'tolerance_max' => '1.0000',
            ]))
            ->assertForbidden();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/quality/inspection-specs', $this->payload($matching, [
                'tolerance_min' => '2.0000',
                'tolerance_max' => '1.0000',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'items.0.tolerance_min',
                'items.0.tolerance_max',
            ]);
    }

    public function test_revisions_retain_old_items_and_spc_uses_only_completed_readings(): void
    {
        $product = Product::factory()->create();
        $spec = $this->createSpec($product, $this->manager);
        $oldItem = $spec->items()->firstOrFail();

        $this->createSpec($product, $this->manager, [
            'parameter_name' => 'Revised negative offset',
            'nominal_value' => '-1.0000',
            'tolerance_min' => '-2.0000',
            'tolerance_max' => '0.0000',
        ]);

        $latest = InspectionSpec::findOrFail($spec->id);
        $newItem = $latest->items()->firstOrFail();
        $revision = InspectionSpecRevision::query()
            ->where('inspection_spec_id', $latest->id)
            ->where('version', 2)
            ->firstOrFail();

        $this->assertNotNull(InspectionSpecItem::withTrashed()->findOrFail($oldItem->id)->deleted_at);
        $this->assertSame($revision->id, $newItem->inspection_spec_revision_id);
        $this->assertCount(2, InspectionSpecRevision::where('inspection_spec_id', $latest->id)->get());

        // Readings must vary. An all-identical series has zero sigma, so
        // compute() returns null by contract and both assertions below would
        // pass for the wrong reason (absent, rather than revision-scoped).
        foreach (range(1, 5) as $index) {
            $inspection = Inspection::query()->create([
                'inspection_number' => 'SPC-OLD-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'stage' => InspectionStage::Outgoing,
                'status' => InspectionStatus::Passed,
                'product_id' => $product->id,
                'inspection_spec_id' => $latest->id,
                'inspection_spec_revision_id' => $oldItem->inspection_spec_revision_id,
                'batch_quantity' => 10,
                'sample_size' => 1,
                'accept_count' => 1,
                'reject_count' => 0,
                'defect_count' => 0,
                'inspector_id' => $this->manager->id,
                'started_at' => now(),
            ]);
            InspectionMeasurement::query()->create([
                'inspection_id' => $inspection->id,
                'inspection_spec_item_id' => $oldItem->id,
                'sample_index' => 1,
                'parameter_name' => $oldItem->parameter_name,
                'parameter_type' => $oldItem->parameter_type,
                'nominal_value' => $oldItem->nominal_value,
                'tolerance_min' => $oldItem->tolerance_min,
                'tolerance_max' => $oldItem->tolerance_max,
                'measured_value' => ['-1.1000', '-0.9000', '-1.0500', '-0.9500', '-1.0000'][$index - 1],
                'is_critical' => true,
                'is_pass' => true,
            ]);
        }

        foreach ([
            InspectionStatus::Passed,
            InspectionStatus::Passed,
            InspectionStatus::Passed,
            InspectionStatus::Passed,
            InspectionStatus::Failed,
            InspectionStatus::Draft,
            InspectionStatus::InProgress,
            InspectionStatus::Cancelled,
        ] as $index => $status) {
            $inspection = Inspection::query()->create([
                'inspection_number' => 'SPC-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'stage' => InspectionStage::Outgoing,
                'status' => $status,
                'product_id' => $product->id,
                'inspection_spec_id' => $latest->id,
                'inspection_spec_revision_id' => $revision->id,
                'batch_quantity' => 10,
                'sample_size' => 1,
                'accept_count' => 0,
                'reject_count' => 1,
                'defect_count' => $status === InspectionStatus::Failed ? 1 : 0,
                'inspector_id' => $this->manager->id,
                'started_at' => now(),
            ]);
            InspectionMeasurement::query()->create([
                'inspection_id' => $inspection->id,
                'inspection_spec_item_id' => $newItem->id,
                'sample_index' => 1,
                'parameter_name' => $newItem->parameter_name,
                'parameter_type' => $newItem->parameter_type,
                'nominal_value' => $newItem->nominal_value,
                'tolerance_min' => $newItem->tolerance_min,
                'tolerance_max' => $newItem->tolerance_max,
                'measured_value' => ['-1.2000', '-0.8000', '-1.1000', '-0.9000', '-1.0000', '-1.9000', '-1.8000', '-1.7000'][$index],
                'is_critical' => true,
                'is_pass' => true,
            ]);
        }

        $result = (new SpcService())->computeForSpec($latest->id);

        $this->assertArrayNotHasKey($oldItem->hash_id, $result);
        $this->assertSame(5, $result[$newItem->hash_id]['sample_count']);
        // The excluded draft/in-progress/cancelled readings sit far from the
        // completed five, so a leaked row would move the mean measurably.
        $this->assertSame(-1.0, $result[$newItem->hash_id]['mean']);
    }

    public function test_spc_reports_nothing_when_the_current_revision_has_no_measurable_variation(): void
    {
        $product = Product::factory()->create();
        $spec = $this->createSpec($product, $this->manager);
        $item = $spec->items()->firstOrFail();
        $revision = InspectionSpecRevision::query()
            ->where('inspection_spec_id', $spec->id)
            ->firstOrFail();

        foreach (range(1, 6) as $index) {
            $inspection = Inspection::query()->create([
                'inspection_number' => 'SPC-FLAT-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'stage' => InspectionStage::Outgoing,
                'status' => InspectionStatus::Passed,
                'product_id' => $product->id,
                'inspection_spec_id' => $spec->id,
                'inspection_spec_revision_id' => $revision->id,
                'batch_quantity' => 10,
                'sample_size' => 1,
                'accept_count' => 1,
                'reject_count' => 0,
                'defect_count' => 0,
                'inspector_id' => $this->manager->id,
                'started_at' => now(),
            ]);
            InspectionMeasurement::query()->create([
                'inspection_id' => $inspection->id,
                'inspection_spec_item_id' => $item->id,
                'sample_index' => 1,
                'parameter_name' => $item->parameter_name,
                'parameter_type' => $item->parameter_type,
                'nominal_value' => $item->nominal_value,
                'tolerance_min' => $item->tolerance_min,
                'tolerance_max' => $item->tolerance_max,
                'measured_value' => '-1.0000',
                'is_critical' => true,
                'is_pass' => true,
            ]);
        }

        // Enough samples, but zero sigma: capability is undefined, not infinite.
        $this->assertSame([], (new SpcService())->computeForSpec($spec->id));

        $this->actingAs($this->viewer, 'sanctum')
            ->getJson("/api/v1/quality/inspection-specs/{$spec->hash_id}/spc")
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.population_policy', 'current_revision_only')
            ->assertJsonPath('meta.revision_version', 1);
    }

    public function test_revision_history_is_readable_to_both_quality_roles_with_complete_old_items(): void
    {
        $product = Product::factory()->create();
        $spec = $this->createSpec($product, $this->manager);
        $firstRevision = InspectionSpecRevision::query()
            ->where('inspection_spec_id', $spec->id)
            ->where('version', 1)
            ->firstOrFail();

        $this->createSpec($product, $this->manager, [
            'parameter_name' => 'Revised negative offset',
            'nominal_value' => '-1.0000',
            'tolerance_min' => '-3.0000',
            'tolerance_max' => '1.0000',
        ]);

        $this->actingAs($this->viewer, 'sanctum')
            ->getJson("/api/v1/quality/inspection-specs/{$spec->hash_id}/revisions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['version' => 1, 'is_current' => false])
            ->assertJsonFragment(['parameter_name' => 'Negative offset', 'tolerance_max' => '0.0000']);

        $this->actingAs($this->viewer, 'sanctum')
            ->getJson("/api/v1/quality/inspection-specs/{$spec->hash_id}/revisions/{$firstRevision->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.items.0.parameter_name', 'Negative offset')
            ->assertJsonPath('data.items.0.tolerance_min', '-2.0000');
    }

    public function test_inspection_detail_exposes_the_pinned_revision_definition(): void
    {
        $product = Product::factory()->create();
        $spec = $this->createSpec($product, $this->manager);
        $revision = InspectionSpecRevision::query()
            ->where('inspection_spec_id', $spec->id)
            ->where('version', 1)
            ->firstOrFail();
        $inspector = $this->userWithPermissions('inspection-detail-viewer', ['quality.inspections.view']);

        $inspection = Inspection::query()->create([
            'inspection_number' => 'HISTORY-001',
            'stage' => InspectionStage::Outgoing,
            'status' => InspectionStatus::Draft,
            'product_id' => $product->id,
            'inspection_spec_id' => $spec->id,
            'inspection_spec_revision_id' => $revision->id,
            'batch_quantity' => 10,
            'sample_size' => 1,
            'accept_count' => 0,
            'reject_count' => 1,
            'defect_count' => 0,
            'inspector_id' => $inspector->id,
            'started_at' => now(),
        ]);

        $this->actingAs($inspector, 'sanctum')
            ->getJson("/api/v1/quality/inspections/{$inspection->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.spec.revision_id', $revision->hash_id)
            ->assertJsonPath('data.spec_revision.version', 1)
            ->assertJsonPath('data.spec_revision.items.0.parameter_name', 'Negative offset');
    }

    public function test_list_keeps_soft_deleted_product_context_and_never_requires_an_undefined_url(): void
    {
        $product = Product::factory()->create([
            'part_number' => 'ARCHIVED-PRODUCT',
            'name' => 'Archived product context',
        ]);
        $spec = $this->createSpec($product, $this->manager);
        $product->delete();

        $response = $this->actingAs($this->viewer, 'sanctum')
            ->getJson('/api/v1/quality/inspection-specs')
            ->assertOk()
            ->assertJsonPath('data.0.id', $spec->hash_id)
            ->assertJsonPath('data.0.product.part_number', 'ARCHIVED-PRODUCT');

        $this->assertNotNull($response->json('data.0.product.deleted_at'));
    }

    public function test_database_rejects_cross_spec_revision_pairs_and_invalid_geometry(): void
    {
        $firstProduct = Product::factory()->create();
        $secondProduct = Product::factory()->create();
        $firstSpec = $this->createSpec($firstProduct, $this->manager);
        $secondSpec = $this->createSpec($secondProduct, $this->manager);
        $firstRevision = InspectionSpecRevision::where('inspection_spec_id', $firstSpec->id)->firstOrFail();
        $secondRevision = InspectionSpecRevision::where('inspection_spec_id', $secondSpec->id)->firstOrFail();

        $this->assertDatabaseRejects(function () use ($firstSpec, $secondRevision): void {
            DB::table('inspection_spec_items')->insert([
                'inspection_spec_id' => $firstSpec->id,
                'inspection_spec_revision_id' => $secondRevision->id,
                'parameter_name' => 'Mismatched lineage',
                'parameter_type' => 'dimensional',
                'nominal_value' => '10.0000',
                'tolerance_min' => '9.0000',
                'tolerance_max' => '11.0000',
                'is_critical' => false,
                'sort_order' => 0,
            ]);
        });

        $this->assertDatabaseRejects(function () use ($firstSpec, $firstRevision): void {
            DB::table('inspection_spec_items')->insert([
                'inspection_spec_id' => $firstSpec->id,
                'inspection_spec_revision_id' => $firstRevision->id,
                'parameter_name' => 'Invalid geometry',
                'parameter_type' => 'dimensional',
                'nominal_value' => '12.0000',
                'tolerance_min' => '9.0000',
                'tolerance_max' => '11.0000',
                'is_critical' => false,
                'sort_order' => 1,
            ]);
        });

        $this->assertDatabaseRejects(function () use ($firstSpec, $secondRevision, $firstProduct): void {
            DB::table('inspections')->insert([
                'inspection_number' => 'MISMATCHED-REVISION-001',
                'stage' => 'outgoing',
                'status' => 'draft',
                'product_id' => $firstProduct->id,
                'inspection_spec_id' => $firstSpec->id,
                'inspection_spec_revision_id' => $secondRevision->id,
                'batch_quantity' => 10,
                'sample_size' => 1,
                'accept_count' => 0,
                'reject_count' => 1,
                'defect_count' => 0,
            ]);
        });
    }

    /** @param callable(): void $writer */
    private function assertDatabaseRejects(callable $writer): void
    {
        try {
            $writer();
            $this->fail('The database accepted an invalid inspection-spec write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function createSpec(Product $product, User $user, array $overrides = []): InspectionSpec
    {
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/quality/inspection-specs', $this->payload($product, $overrides))
            ->assertOk()
            ->json('data');

        return InspectionSpec::findOrFail(InspectionSpec::decodeHash($response['id']));
    }

    private function payload(Product $product, array $overrides = []): array
    {
        $row = array_merge([
            'parameter_name' => 'Negative offset',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '-1.0000',
            'tolerance_min' => '-2.0000',
            'tolerance_max' => '0.0000',
            'is_critical' => true,
            'notes' => 'Recorded for revision history.',
        ], $overrides);

        return [
            'product_id' => $product->hash_id,
            'notes' => 'Spec revision test',
            'items' => [$row],
        ];
    }

    private function userWithPermissions(string $slug, array $permissions): User
    {
        $role = Role::query()->create([
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
        ]);
        $permissionIds = [];
        foreach ($permissions as $permissionSlug) {
            $permissionIds[] = Permission::query()->firstOrCreate(
                ['slug' => $permissionSlug],
                ['name' => $permissionSlug, 'module' => 'quality'],
            )->id;
        }
        $role->permissions()->sync($permissionIds);

        return User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }
}
