<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterHygieneAffectedRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.dss', true, 'modules');
        app(DssParameterService::class)->set('dss.master_hygiene', 'mode', 'advisory');
    }

    public function test_lists_live_records_with_current_requirements_and_record_actions(): void
    {
        $first = $this->itemWithReorderGap('RM-01');
        $second = $this->itemWithReorderGap('RM-02');
        $recommendation = $this->recommendation('items_missing_reorder_point', ['inventory.items.manage']);
        $user = $this->userWithPermissions(['dss.view', 'inventory.items.manage']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$recommendation->hash_id}/affected-records?per_page=1")
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('historical', false)
            ->assertJsonPath('data.0.reference', $first->code)
            ->assertJsonPath('data.0.name', $first->name)
            ->assertJsonPath('data.0.requirement', 'Usable reorder point')
            ->assertJsonPath('data.0.destination', "/inventory/items/{$first->hash_id}/edit");

        $cursor = $response->json('next_cursor');
        $this->assertIsString($cursor);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations/'.$recommendation->hash_id.'/affected-records?per_page=1&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonPath('data.0.reference', $second->code)
            ->assertJsonPath('next_cursor', null);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dss/recommendations/'.$recommendation->hash_id.'/affected-records?search=RM-02')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.reference', $second->code);
    }

    public function test_requires_the_gap_permission_even_when_a_recommendation_row_is_mis_scoped(): void
    {
        $recommendation = $this->recommendation('items_missing_reorder_point', ['accounting.customers.manage']);
        $user = $this->userWithPermissions(['dss.view', 'accounting.customers.manage']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$recommendation->hash_id}/affected-records")
            ->assertForbidden();
    }

    public function test_hides_affected_records_from_users_without_source_permission(): void
    {
        $recommendation = $this->recommendation('items_missing_reorder_point', ['inventory.items.manage']);
        $user = $this->userWithPermissions(['dss.view', 'purchasing.po.create']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$recommendation->hash_id}/affected-records")
            ->assertForbidden();
    }

    public function test_does_not_attach_live_records_to_historical_recommendations(): void
    {
        $this->itemWithReorderGap('RM-OLD');
        $recommendation = $this->recommendation('items_missing_reorder_point', ['inventory.items.manage']);
        $recommendation->forceFill(['status' => 'resolved'])->save();
        $user = $this->userWithPermissions(['dss.view', 'inventory.items.manage']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$recommendation->hash_id}/affected-records")
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', [])
            ->assertJsonPath('historical', true);
    }

    public function test_projects_verified_resolution_in_readable_source_language(): void
    {
        $recommendation = $this->recommendation('items_missing_reorder_point', ['inventory.items.manage']);
        $recommendation->forceFill([
            'status' => 'resolved',
            'verification_status' => 'verified_clear',
            'resolution_evidence' => [
                'source_condition' => 'master_data_gap.items_missing_reorder_point',
                'verifier' => 'master_hygiene_source',
                'run_reference' => 'run_hash',
                'proof' => 'No current records match this master-data gap.',
                'observed_at' => now()->toISOString(),
            ],
        ])->save();
        $user = $this->userWithPermissions(['dss.view', 'inventory.items.manage']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dss/recommendations/{$recommendation->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.resolution_evidence.condition', 'Usable item reorder point')
            ->assertJsonPath('data.resolution_evidence.verification_method', 'Current master-data records')
            ->assertJsonPath('data.resolution_evidence.proof', 'No current records match this master-data gap.');
    }

    private function itemWithReorderGap(string $code): Item
    {
        return Item::factory()->create([
            'code' => $code,
            'reorder_point' => 0,
            'safety_stock' => 5,
            'lead_time_days' => 7,
            'standard_cost' => 100,
        ]);
    }

    private function recommendation(string $gap, array $permissions): DssRecommendation
    {
        $run = DssRunLog::query()->create([
            'detector_id' => 'dss.master_hygiene',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return DssRecommendation::query()->create([
            'detector_id' => 'dss.master_hygiene',
            'detector_version' => 'v3',
            'domain' => 'platform',
            'type' => 'master_hygiene',
            'severity' => 'warning',
            'dedupe_key' => 'hygiene:'.$gap,
            'rationale' => ['gap' => $gap, 'affected_count' => 1],
            'owner_permissions' => $permissions,
            'decision_code' => 'master_data_gap.'.$gap,
            'verification_context' => ['gap' => $gap],
            'last_evaluated_at' => now(),
            'last_evaluated_run_id' => $run->id,
        ]);
    }

    private function userWithPermissions(array $slugs): User
    {
        $role = Role::query()->create([
            'name' => 'Master hygiene test '.uniqid(),
            'slug' => 'master-hygiene-test-'.uniqid(),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }
}
