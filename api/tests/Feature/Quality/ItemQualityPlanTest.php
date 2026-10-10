<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Services\ItemQualityPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

    public function test_checklist_authoring_rejects_numeric_fields_and_pins_written_criteria(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $role = Role::firstOrCreate(['slug' => 'checklist-planner'], ['name' => 'Checklist planner']);
        $permission = Permission::firstOrCreate(['slug' => 'quality.specs.manage'], ['name' => 'Manage specifications', 'module' => 'quality']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');
        $item = Item::factory()->create();
        $payload = ['capture_method' => 'checklist', 'sampling_method' => 'full',
            'parameters' => [['parameter_name' => 'Condition', 'acceptance_criterion' => 'No visible contamination.']]];
        $url = "/api/v1/inventory/items/{$item->hash_id}/quality-plans";
        $this->postJson($url, [...$payload, 'parameters' => [[...$payload['parameters'][0], 'nominal_value' => null]]])
            ->assertUnprocessable()->assertJsonValidationErrors('parameters.0.nominal_value');
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.capture_method', 'checklist')
            ->assertJsonPath('data.parameters.0.acceptance_criterion', 'No visible contamination.');
    }

    public function test_global_checklist_capture_rejects_measurement_plans_without_usable_instructions(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $role = Role::firstOrCreate(['slug' => 'global-checklist-planner'], ['name' => 'Global checklist planner']);
        $permission = Permission::firstOrCreate(['slug' => 'quality.specs.manage'], ['name' => 'Manage specifications', 'module' => 'quality']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');
        $item = Item::factory()->create();
        $payload = [
            'capture_method' => 'measurement',
            'sampling_method' => 'full',
            'parameters' => [['parameter_name' => 'Surface condition', 'parameter_type' => 'visual']],
        ];

        $this->postJson("/api/v1/inventory/items/{$item->hash_id}/quality-plans", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parameters.0.acceptance_criterion');

        $this->assertDatabaseCount('item_quality_plans', 0);
    }

    public function test_global_checklist_capture_rejects_duplicate_measurement_plan_checks(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $role = Role::firstOrCreate(['slug' => 'duplicate-checklist-planner'], ['name' => 'Duplicate checklist planner']);
        $permission = Permission::firstOrCreate(['slug' => 'quality.specs.manage'], ['name' => 'Manage specifications', 'module' => 'quality']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'sanctum');
        $item = Item::factory()->create();
        $payload = [
            'capture_method' => 'measurement',
            'sampling_method' => 'full',
            'parameters' => [
                ['parameter_name' => 'Surface condition', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks.'],
                ['parameter_name' => ' surface   condition ', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks.'],
            ],
        ];

        $this->postJson("/api/v1/inventory/items/{$item->hash_id}/quality-plans", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parameters.1.parameter_name');

        $this->assertDatabaseCount('item_quality_plans', 0);
    }

    public function test_item_quality_plan_service_rejects_duplicate_checks_when_global_capture_is_active(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $item = Item::factory()->create();

        $this->expectException(ValidationException::class);
        app(ItemQualityPlanService::class)->createRevision($item, [
            'capture_method' => 'measurement',
            'sampling_method' => 'full',
            'parameters' => [
                ['parameter_name' => 'Surface condition', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks.'],
                ['parameter_name' => ' surface   condition ', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks.'],
            ],
        ], User::factory()->create());
    }

    public function test_item_quality_plan_service_rejects_missing_instructions_when_global_capture_is_active(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $item = Item::factory()->create();

        $this->expectException(ValidationException::class);
        app(ItemQualityPlanService::class)->createRevision($item, [
            'capture_method' => 'measurement',
            'sampling_method' => 'full',
            'parameters' => [['parameter_name' => 'Surface condition', 'parameter_type' => 'visual']],
        ], User::factory()->create());
    }

    public function test_future_revision_never_extends_previous_expiry_and_deactivation_keeps_valid_dates(): void
    {
        $item = Item::factory()->create();
        $user = User::factory()->create();
        $service = app(ItemQualityPlanService::class);
        $data = ['sampling_method' => 'full', 'parameters' => [[
            'parameter_name' => 'Surface', 'parameter_type' => 'visual',
            'acceptance_criterion' => 'No visible cracks or deformation.',
        ]]];
        $current = $service->createRevision($item, [...$data, 'effective_from' => today()->toDateString(),
            'effective_to' => today()->addDay()->toDateString()], $user);
        $future = $service->createRevision($item, [...$data, 'effective_from' => today()->addDays(7)->toDateString()], $user);
        $this->assertSame(today()->addDay()->toDateString(), $current->fresh()->effective_to->toDateString());
        $retired = $service->deactivate($future, $user);
        $this->assertFalse($retired->is_active);
        $this->assertGreaterThanOrEqual($retired->effective_from->toDateString(), $retired->effective_to->toDateString());
    }
}
