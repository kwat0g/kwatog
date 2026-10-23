<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Models\DssRecommendation;
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

    public function detect(array $context = []): array
    {
        return [];
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

    private function makeRecommendation(string $detector = 'test.advisory'): DssRecommendation
    {
        return DssRecommendation::create([
            'detector_id' => $detector,
            'domain' => 'test',
            'type' => 'test_type',
            'severity' => 'warning',
            'priority_score' => 0.5,
            'rationale' => ['rule' => 'x'],
            'dedupe_key' => 'k-'.uniqid(),
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
            ->postJson("/api/v1/dss/recommendations/{$hidden->id}/actions", ['action' => 'accept'])
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
