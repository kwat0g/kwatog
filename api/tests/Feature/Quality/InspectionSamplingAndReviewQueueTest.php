<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Listeners\TriggerInProcessQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Services\AqlSampleSizeService;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O2C audit 2026-09-25 — quality touchpoints on the order-to-cash chain.
 *
 *  - In-process QC sampled 100% of the WO target: hundreds of rows before any
 *    output, and no inspection at all past quality.full_sampling.max_units.
 *  - A result awaiting its checker told nobody, so the outgoing lot sat and no
 *    delivery was drafted.
 *  - The AQL 0.65 / Level II plan was looser than ANSI/ASQ Z1.4 for 281–500.
 */
class InspectionSamplingAndReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['is_active' => true, 'role_id' => Role::query()->where('slug', $slug)->value('id')]);
    }

    private function startedWorkOrder(int $target, User $by): WorkOrder
    {
        $product = Product::factory()->create();
        app(InspectionSpecService::class)->upsertForProduct($product->id, [
            ['parameter_name' => 'Outer diameter', 'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm',
                'nominal_value' => '10.000', 'tolerance_min' => '9.950', 'tolerance_max' => '10.050', 'is_critical' => true],
            ['parameter_name' => 'Flash', 'parameter_type' => 'visual', 'is_critical' => false],
        ], $by->id);

        return WorkOrder::factory()->create([
            'product_id' => $product->id,
            'quantity_target' => $target,
            'status' => WorkOrderStatus::InProgress->value,
            'created_by' => $by->id,
        ]);
    }

    public function test_in_process_qc_takes_a_small_fixed_sample_even_for_a_large_run(): void
    {
        $user = $this->userWithRole('production_manager');
        $this->actingAs($user);
        $wo = $this->startedWorkOrder(5000, $user);

        app(TriggerInProcessQC::class)->handle(new WorkOrderStatusChanged($wo, 'confirmed', 'in_progress'));

        $inspection = Inspection::query()->where('entity_id', $wo->id)->where('stage', 'in_process')->firstOrFail();
        $this->assertSame(5000, (int) $inspection->batch_quantity);
        $this->assertSame(5, (int) $inspection->sample_size);
        $this->assertSame(10, InspectionMeasurement::query()->where('inspection_id', $inspection->id)->count(), '5 pieces × 2 parameters');
    }

    public function test_in_process_sample_never_exceeds_the_work_order(): void
    {
        $user = $this->userWithRole('production_manager');
        $this->actingAs($user);
        $wo = $this->startedWorkOrder(3, $user);

        app(TriggerInProcessQC::class)->handle(new WorkOrderStatusChanged($wo, 'confirmed', 'in_progress'));

        $this->assertSame(3, (int) Inspection::query()->where('entity_id', $wo->id)->where('stage', 'in_process')->value('sample_size'));
    }

    private function awaitingReviewOutgoing(User $maker): Inspection
    {
        $inspection = Inspection::query()->create([
            'inspection_number' => 'QC-T-'.substr(uniqid(), -6),
            'stage' => InspectionStage::Outgoing,
            'status' => InspectionStatus::InProgress,
            'entity_type' => 'work_order',
            'entity_id' => 1,
            'product_id' => Product::factory()->create()->id,
            'batch_quantity' => 1,
            'sample_size' => 1,
            'accept_count' => 0,
            'reject_count' => 1,
            'inspector_id' => $maker->id,
            'started_at' => now(),
        ]);
        InspectionMeasurement::query()->create([
            'inspection_id' => $inspection->id, 'sample_index' => 1, 'parameter_name' => 'Visual',
            'parameter_type' => 'visual', 'is_critical' => true, 'is_pass' => true,
        ]);

        return app(InspectionService::class)->complete($inspection->fresh(['measurements']), $maker);
    }

    public function test_a_result_awaiting_review_notifies_the_checkers_but_not_the_maker(): void
    {
        $maker = $this->userWithRole('qc_inspector');
        $checker = $this->userWithRole('production_manager');
        $outsider = $this->userWithRole('warehouse_staff');

        $inspection = $this->awaitingReviewOutgoing($maker);

        $this->assertSame(InspectionStatus::AwaitingReview, $inspection->status);
        $notified = fn (User $u): bool => DB::table('notifications')
            ->where('notifiable_id', $u->id)->where('type', 'quality.inspection_awaiting_review')->exists();
        $this->assertTrue($notified($checker));
        $this->assertFalse($notified($maker));
        $this->assertFalse($notified($outsider));
        $this->assertSame(
            "/quality/inspections/{$inspection->hash_id}",
            json_decode((string) DB::table('notifications')->where('notifiable_id', $checker->id)->value('data'), true)['link_to'],
        );
    }

    public function test_the_action_center_queues_the_review_for_checkers_only(): void
    {
        $maker = $this->userWithRole('qc_inspector');
        $checker = $this->userWithRole('production_manager');
        $inspection = $this->awaitingReviewOutgoing($maker);
        $key = 'quality:inspection-review:'.$inspection->hash_id;
        $keys = fn (User $u): array => array_column(
            $this->actingAs($u)->getJson('/api/v1/dashboards/action-center')->assertOk()->json('data.items') ?? [],
            'id',
        );

        $this->assertContains($key, $keys($checker));
        $this->assertNotContains($key, $keys($maker), 'the maker never sees their own result as review work');

        $this->actingAs($checker)
            ->patchJson('/api/v1/dashboards/action-center/tasks', ['item_ids' => [$key], 'action' => 'claim'])
            ->assertOk();
        $viewOnly = $this->userWithRole('warehouse_staff');
        $viewOnly->role->permissions()->syncWithoutDetaching([
            Permission::query()->where('slug', 'quality.inspections.view')->value('id'),
        ]);
        $viewOnly->flushPermissionsCache();
        $this->actingAs($viewOnly)
            ->patchJson('/api/v1/dashboards/action-center/tasks', ['item_ids' => [$key], 'action' => 'claim'])
            ->assertForbidden();
    }

    public function test_aql_plan_follows_z14_at_aql_065_level_ii(): void
    {
        $plan = fn (int $lot): array => array_intersect_key(AqlSampleSizeService::forBatch($lot), array_flip(['code', 'sample_size', 'accept', 'reject']));

        $this->assertSame(['code' => 'F', 'sample_size' => 12, 'accept' => 0, 'reject' => 1], $plan(12), 'lot under 20 is inspected 100%');
        $this->assertSame(['code' => 'F', 'sample_size' => 20, 'accept' => 0, 'reject' => 1], $plan(150));
        $this->assertSame(['code' => 'F', 'sample_size' => 20, 'accept' => 0, 'reject' => 1], $plan(280), 'G arrows up to F');
        $this->assertSame(['code' => 'J', 'sample_size' => 80, 'accept' => 1, 'reject' => 2], $plan(400), 'H arrows down to J');
        $this->assertSame(['code' => 'J', 'sample_size' => 80, 'accept' => 1, 'reject' => 2], $plan(1000));
        $this->assertSame(['code' => 'K', 'sample_size' => 125, 'accept' => 2, 'reject' => 3], $plan(2000));
    }

    public function test_aql_migration_reconciles_before_it_changes_the_plan(): void
    {
        $migration = require database_path('migrations/0563_align_aql_sample_plan_with_z14.php');
        $settings = app(SettingsService::class);
        $lot400 = fn (): int => AqlSampleSizeService::forBatch(400)['sample_size'];

        $migration->down();
        $settings->flushCache();
        $this->assertSame(50, $lot400(), 'down() restores the plan 0409 seeded');

        $migration->up();
        $migration->up();
        $settings->flushCache();
        $this->assertSame(80, $lot400(), 'up() is idempotent once aligned');

        $settings->set('quality.aql.sample_plan', ['tiny_batch' => ['code' => 'A', 'accept' => 0, 'reject' => 1], 'rows' => [], 'overflow' => ['code' => 'Q', 'sample_size' => 1, 'accept' => 0, 'reject' => 1]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has been customised');
        $migration->down();
    }
}
