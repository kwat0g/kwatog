<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemQualityPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_a_revision_retires_the_previous_plan_and_marks_item_ready(): void
    {
        $role = Role::query()->create(['name' => 'Quality Planner', 'slug' => 'quality-planner-test']);
        foreach (['inventory.view', 'quality.specs.manage'] as $slug) {
            $permission = Permission::query()->create(['name' => $slug, 'slug' => $slug, 'module' => 'quality']);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['role_id' => $role->id]);
        $item = Item::factory()->create();
        $payload = [
            'sampling_method' => 'fixed',
            'fixed_sample_size' => 3,
            'parameters' => [[
                'parameter_name' => 'Moisture',
                'parameter_type' => 'dimensional',
                'unit_of_measure' => '%',
                'tolerance_min' => 0,
                'tolerance_max' => 0.2,
                'is_critical' => true,
            ]],
        ];

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/inventory/items/{$item->hash_id}/quality-plans", $payload)
            ->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.is_active', true);
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/inventory/items/{$item->hash_id}/quality-plans", [
            ...$payload, 'notes' => 'Tightened incoming control.',
        ])->assertCreated()->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('item_quality_plans', ['item_id' => $item->id, 'version' => 1, 'is_active' => false]);
        $this->assertDatabaseHas('item_quality_plans', ['item_id' => $item->id, 'version' => 2, 'is_active' => true]);
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/inventory/items/{$item->hash_id}")
            ->assertOk()->assertJsonPath('data.quality_plan_ready', true);
    }
}
