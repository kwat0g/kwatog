<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityPlanRolloutCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollout_is_dry_run_by_default_and_apply_is_idempotent(): void
    {
        $role = Role::query()->create(['name' => 'System Administrator', 'slug' => 'system_admin']);
        User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $resin = Item::factory()->create([
            'code' => 'RM-RESIN', 'name' => 'ABS Resin', 'item_type' => 'raw_material', 'is_active' => true,
        ]);
        $general = Item::factory()->create([
            'code' => 'RM-METAL', 'name' => 'Metal Insert', 'item_type' => 'raw_material', 'is_active' => true,
        ]);
        Item::factory()->create(['code' => 'FG-001', 'item_type' => 'finished_good', 'is_active' => true]);

        $this->artisan('quality:plans:rollout')->assertSuccessful()
            ->expectsOutputToContain('Dry-run: 2 plan(s) would be created');
        $this->assertDatabaseCount('item_quality_plans', 0);

        $this->artisan('quality:plans:rollout', ['--apply' => true])->assertSuccessful()
            ->expectsOutputToContain('Created 2 baseline plan(s)');
        $this->assertDatabaseCount('item_quality_plans', 2);
        $this->assertSame('Certificate of analysis verified', $resin->qualityPlans()->sole()->parameters[0]['parameter_name']);
        $this->assertSame('Material identity and specification verified', $general->qualityPlans()->sole()->parameters[0]['parameter_name']);

        $this->artisan('quality:plans:rollout', ['--apply' => true])->assertSuccessful()
            ->expectsOutput('Every eligible item already has an effective quality plan.');
        $this->assertDatabaseCount('item_quality_plans', 2);
    }

    public function test_rollout_fails_safely_without_an_active_system_administrator(): void
    {
        Item::factory()->create(['item_type' => 'raw_material', 'is_active' => true]);

        $this->artisan('quality:plans:rollout', ['--apply' => true])->assertFailed()
            ->expectsOutput('No active system administrator is available to own generated plan revisions.');
        $this->assertDatabaseCount('item_quality_plans', 0);
    }
}
