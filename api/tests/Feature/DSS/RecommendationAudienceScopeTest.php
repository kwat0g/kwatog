<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AudienceFakeDetector implements DetectorInterface
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

class RecommendationAudienceScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.dss', true, 'modules');

        $registry = app(DetectorRegistry::class);
        $registry->register('test.advisory', AudienceFakeDetector::class);
        app(DssParameterService::class)->set('test.advisory', 'mode', 'advisory');
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::query()->where('slug', $slug)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeRecommendation(array $audienceRoles): DssRecommendation
    {
        return DssRecommendation::create([
            'detector_id' => 'test.advisory',
            'domain' => 'test',
            'type' => 'test_type',
            'severity' => 'warning',
            'priority_score' => 0.5,
            'rationale' => array_merge(['rule' => 'x'], $audienceRoles === []
                ? []
                : ['audience_roles' => $audienceRoles]),
            'dedupe_key' => 'k-'.uniqid(),
        ]);
    }

    public function test_list_shows_only_own_audience_rows(): void
    {
        $ppc = $this->userWithRole('ppc_head');
        $warehouse = $this->userWithRole('warehouse_staff');
        $forPpc = $this->makeRecommendation(['ppc_head']);
        $forWarehouse = $this->makeRecommendation(['warehouse_staff']);

        $this->actingAs($ppc, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonFragment(['id' => $forPpc->hash_id])
            ->assertJsonMissing(['id' => $forWarehouse->hash_id]);

        $this->actingAs($warehouse, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonFragment(['id' => $forWarehouse->hash_id])
            ->assertJsonMissing(['id' => $forPpc->hash_id]);
    }

    public function test_detail_and_actions_enforce_audience(): void
    {
        $ppc = $this->userWithRole('ppc_head');
        $warehouse = $this->userWithRole('warehouse_staff');
        $forWarehouse = $this->makeRecommendation(['warehouse_staff']);

        $this->actingAs($ppc, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$forWarehouse->id}")
            ->assertForbidden();

        $this->actingAs($ppc, 'sanctum')
            ->postJson("/api/v1/dss/recommendations/{$forWarehouse->id}/actions", ['action' => 'accept'])
            ->assertForbidden();
    }

    public function test_rows_without_audience_stay_visible_to_all(): void
    {
        $ppc = $this->userWithRole('ppc_head');
        $open = $this->makeRecommendation([]);

        $this->actingAs($ppc, 'sanctum')
            ->getJson('/api/v1/dss/recommendations')
            ->assertOk()
            ->assertJsonFragment(['id' => $open->hash_id]);
    }
}
