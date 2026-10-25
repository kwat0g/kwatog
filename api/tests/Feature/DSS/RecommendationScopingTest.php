<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopingFakeDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'test.advisory';
    }

    public function domain(): string
    {
        return 'test';
    }

    public function ownerPermissions(): array
    {
        return ['dss.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        return new DetectorScanResult([]);
    }
}

class ScopingRestrictedDetector extends ScopingFakeDetector
{
    public function id(): string
    {
        return 'test.restricted';
    }

    public function ownerPermissions(): array
    {
        return ['forecasting.view'];
    }
}

class ScopingShadowDetector extends ScopingFakeDetector
{
    public function id(): string
    {
        return 'test.shadowed';
    }
}

class RecommendationScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.dss', true, 'modules');

        $registry = app(DetectorRegistry::class);
        $registry->register('test.advisory', ScopingFakeDetector::class);
        $registry->register('test.restricted', ScopingRestrictedDetector::class);
        $registry->register('test.shadowed', ScopingShadowDetector::class);

        $params = app(DssParameterService::class);
        $params->set('test.advisory', 'mode', 'advisory');
        $params->set('test.restricted', 'mode', 'advisory');
        // test.shadowed stays on the engine default (shadow): never displayed.
    }

    private function userWithPermissions(array $slugs): User
    {
        $role = Role::create([
            'name' => 'DSS Test '.uniqid(),
            'slug' => 'dss-test-'.uniqid(),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeRecommendation(string $detector = 'test.advisory', bool $withEvaluation = true): DssRecommendation
    {
        $run = $withEvaluation ? DssRunLog::query()->create([
            'detector_id' => $detector,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
        ]) : null;

        return DssRecommendation::create([
            'detector_id' => $detector,
            'domain' => 'test',
            'type' => 'test_type',
            'severity' => 'warning',
            'priority_score' => 0.5,
            'rationale' => ['rule' => 'x'],
            'dedupe_key' => 'k-'.uniqid(),
            'last_evaluated_at' => $run?->finished_at,
            'last_evaluated_run_id' => $run?->id,
        ]);
    }

    public function test_without_dss_view_is_forbidden(): void
    {
        $user = $this->userWithPermissions(['forecasting.view']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertForbidden();
    }

    public function test_rows_from_unowned_detectors_are_invisible(): void
    {
        // Holds dss.view but not forecasting.view: the restricted row must not
        // leak through the list OR the detail/action endpoints.
        $user = $this->userWithPermissions(['dss.view']);
        $hidden = $this->makeRecommendation('test.restricted');
        $visible = $this->makeRecommendation('test.advisory');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonFragment(['id' => $visible->hash_id])
            ->assertJsonMissing(['id' => $hidden->hash_id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$hidden->id}")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$hidden->id}/events")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$hidden->id}/actions", ['action' => 'accept'])
            ->assertForbidden();
    }

    public function test_visibility_is_applied_before_pagination_and_totals(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $visible = $this->makeRecommendation('test.advisory');
        $this->makeRecommendation('test.restricted');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations?per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $visible->hash_id]);
    }

    public function test_owner_permissions_are_applied_per_recommendation(): void
    {
        $viewer = $this->userWithPermissions(['dss.view']);
        $accounting = $this->userWithPermissions(['dss.view', 'accounting.view']);
        $restricted = $this->makeRecommendation();
        $restricted->forceFill(['owner_permissions' => ['accounting.view']])->save();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$restricted->id}")
            ->assertForbidden();

        $this->actingAs($accounting, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $restricted->hash_id]);
    }

    public function test_master_hygiene_recommendations_follow_each_source_read_permission(): void
    {
        app(DssParameterService::class)->set('dss.master_hygiene', 'mode', 'advisory');
        $inventoryGap = $this->makeRecommendation('dss.master_hygiene');
        $inventoryGap->forceFill(['owner_permissions' => ['inventory.items.manage']])->save();
        $bomGap = $this->makeRecommendation('dss.master_hygiene');
        $bomGap->forceFill(['owner_permissions' => ['mrp.boms.view']])->save();
        $inventoryUser = $this->userWithPermissions(['dss.view', 'inventory.items.manage']);

        $this->actingAs($inventoryUser, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $inventoryGap->hash_id])
            ->assertJsonMissing(['id' => $bomGap->hash_id]);

        $this->actingAs($inventoryUser, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$bomGap->id}")
            ->assertForbidden();
    }

    public function test_owner_roles_are_applied_per_recommendation(): void
    {
        $hr = User::factory()->withRole('hr_officer')->create(['is_active' => true]);
        $finance = User::factory()->withRole('finance_officer')->create(['is_active' => true]);
        $restricted = $this->makeRecommendation();
        $restricted->forceFill(['owner_role_slugs' => ['hr_officer']])->save();

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($finance, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$restricted->id}")
            ->assertForbidden();
        $this->actingAs($finance, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$restricted->id}/events")
            ->assertForbidden();

        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $restricted->hash_id]);
    }

    public function test_source_references_and_resolution_evidence_are_projected_with_source_permissions(): void
    {
        $viewer = $this->userWithPermissions(['dss.view', 'production.work_orders.view']);
        $recommendation = $this->makeRecommendation();
        $recommendation->forceFill([
            'source_references' => [
                ['source_kind' => 'work_order', 'public_id' => 'wo_hash', 'display_reference' => 'WO-42', 'destination' => '/production/work-orders/wo_hash'],
                ['source_kind' => 'ncr', 'public_id' => 'ncr_hash', 'display_reference' => 'NCR-42', 'destination' => '/quality/ncrs/ncr_hash'],
                ['source_kind' => 'supplier', 'public_id' => 'supplier_hash', 'display_reference' => 'SUP-42', 'destination' => '/purchasing/suppliers/supplier_hash'],
            ],
            'decision_code' => 'work_order.intervention',
            'policy_version' => '2',
            'verification_status' => 'still_open',
            'resolution_evidence' => [
                'source_condition' => 'work_order_overdue',
                'verifier' => 'work_order_status',
                'run_reference' => 'run_hash',
                'proof' => 'Work order is no longer active.',
                'observed_at' => now()->toISOString(),
            ],
            'verification_context' => ['source_id' => 42],
        ])->save();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonFragment([
                'source_kind' => 'work_order',
                'public_id' => 'wo_hash',
                'display_reference' => 'WO-42',
                'destination' => '/production/work-orders/wo_hash',
            ])
            ->assertJsonPath('data.0.decision_code', 'work_order.intervention')
            ->assertJsonPath('data.0.policy_version', '2')
            ->assertJsonPath('data.0.resolution_evidence', null)
            ->assertJsonPath('data.0.subject_type', null)
            ->assertJsonMissingPath('data.0.verification_context');

        $recommendation->forceFill(['verification_status' => 'verified_clear'])->save();
        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonFragment([
                'condition' => 'Production deadline',
                'verification_method' => 'Work order status',
                'proof' => 'Work order is no longer active.',
                'verified_at' => $recommendation->resolution_evidence['observed_at'],
                'run_reference' => 'run_hash',
            ])
            ->assertJsonMissingPath('data.0.resolution_evidence.source_condition')
            ->assertJsonMissingPath('data.0.resolution_evidence.verifier');
    }

    public function test_ncr_source_reference_requires_quality_ncr_view(): void
    {
        $viewer = $this->userWithPermissions(['dss.view']);
        $recommendation = $this->makeRecommendation();
        $recommendation->forceFill([
            'source_references' => [[
                'source_kind' => 'ncr',
                'public_id' => 'ncr_hash',
                'display_reference' => 'NCR-42',
                'destination' => '/quality/ncrs/ncr_hash',
            ]],
        ])->save();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonPath('data.0.source_references', []);
    }

    public function test_sales_order_source_reference_requires_sales_order_view(): void
    {
        $viewer = $this->userWithPermissions(['dss.view', 'production.work_orders.view']);
        $salesViewer = $this->userWithPermissions(['dss.view', 'production.work_orders.view', 'crm.sales_orders.view']);
        $recommendation = $this->makeRecommendation();
        $recommendation->forceFill([
            'source_references' => [[
                'source_kind' => 'sales_order',
                'public_id' => 'order_hash',
                'display_reference' => 'SO-42',
                'destination' => '/crm/sales-orders/order_hash',
            ]],
        ])->save();

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonPath('data.0.source_references', []);

        $this->actingAs($salesViewer, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonFragment([
                'source_kind' => 'sales_order',
                'public_id' => 'order_hash',
                'display_reference' => 'SO-42',
                'destination' => '/crm/sales-orders/order_hash',
            ]);
    }

    public function test_disabled_detector_recommendations_are_hidden(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();
        app(DssParameterService::class)->set('test.advisory', 'enabled', false);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$rec->id}")
            ->assertForbidden();
    }

    public function test_successful_evaluation_survives_skipped_run_but_legacy_row_stays_hidden(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $rec = $this->makeRecommendation();
        $legacy = $this->makeRecommendation(withEvaluation: false);
        DssRunLog::query()->create([
            'detector_id' => 'test.advisory',
            'status' => 'skipped',
            'started_at' => now()->addSecond(),
            'finished_at' => now()->addSeconds(2),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['id' => $rec->hash_id])
            ->assertJsonMissing(['id' => $legacy->hash_id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$rec->id}")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$legacy->id}")
            ->assertForbidden();
    }

    public function test_shadow_detector_rows_never_reach_users(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $shadowed = $this->makeRecommendation('test.shadowed');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonMissing(['id' => $shadowed->hash_id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$shadowed->id}")
            ->assertForbidden();
    }

    public function test_rows_from_unknown_detectors_are_hidden(): void
    {
        $user = $this->userWithPermissions(['dss.view']);
        $orphan = $this->makeRecommendation('test.unregistered');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonMissing(['id' => $orphan->hash_id]);
    }

    public function test_feature_flag_off_returns_feature_disabled(): void
    {
        app(SettingsService::class)->set('modules.dss', false, 'modules');
        $user = $this->userWithPermissions(['dss.view']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_disabled');
    }
}
