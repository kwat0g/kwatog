<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\SuccessionPriority;
use App\Modules\HR\Enums\SuccessionReadiness;
use App\Modules\HR\Enums\SuccessionStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Models\SuccessionPlan;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuccessionPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $hrUser;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $hrRole = Role::where('slug', 'hr_officer')->firstOrFail();
        $this->hrUser = User::factory()->create(['role_id' => $hrRole->id, 'is_active' => true]);

        $empRole = Role::where('slug', 'employee')->firstOrFail();
        $this->employee = User::factory()->create(['role_id' => $empRole->id, 'is_active' => true]);
    }

    public function test_hr_can_create_succession_plan(): void
    {
        $position = Position::factory()->create();
        $incumbent = Employee::factory()->create(['position_id' => $position->id]);
        $successor = Employee::factory()->create();

        $response = $this->actingAs($this->hrUser)->postJson('/api/v1/hr/succession-plans', [
            'position_id'       => $position->id,
            'incumbent_id'      => $incumbent->id,
            'successor_id'      => $successor->id,
            'readiness'         => SuccessionReadiness::Ready1Year->value,
            'priority'          => SuccessionPriority::High->value,
            'development_notes' => 'Needs leadership training',
            'target_date'       => '2027-06-01',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.readiness', 'ready_1_year');
        $response->assertJsonPath('data.priority', 'high');
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('succession_plans', [
            'position_id'  => $position->id,
            'successor_id' => $successor->id,
            'status'       => 'active',
        ]);
    }

    public function test_hr_can_list_succession_plans(): void
    {
        $position = Position::factory()->create();
        $emp = Employee::factory()->create();
        $plan = new SuccessionPlan();
        $plan->fill([
            'position_id'  => $position->id,
            'successor_id' => $emp->id,
            'readiness'    => SuccessionReadiness::ReadyNow->value,
            'priority'     => SuccessionPriority::Critical->value,
            'created_by'   => $this->hrUser->id,
        ]);
        $plan->status = SuccessionStatus::Active;
        $plan->save();

        $response = $this->actingAs($this->hrUser)->getJson('/api/v1/hr/succession-plans');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_hr_can_update_succession_plan(): void
    {
        $position = Position::factory()->create();
        $emp = Employee::factory()->create();
        $plan = new SuccessionPlan();
        $plan->fill([
            'position_id'  => $position->id,
            'successor_id' => $emp->id,
            'readiness'    => SuccessionReadiness::DevelopmentNeeded->value,
            'priority'     => SuccessionPriority::Medium->value,
            'created_by'   => $this->hrUser->id,
        ]);
        $plan->status = SuccessionStatus::Active;
        $plan->save();

        $response = $this->actingAs($this->hrUser)->putJson("/api/v1/hr/succession-plans/{$plan->hash_id}", [
            'readiness' => SuccessionReadiness::Ready1Year->value,
            'status'    => SuccessionStatus::Completed->value,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('succession_plans', [
            'id'        => $plan->id,
            'readiness' => 'ready_1_year',
            'status'    => 'completed',
        ]);
    }

    public function test_employee_cannot_access_succession_plans(): void
    {
        $response = $this->actingAs($this->employee)->getJson('/api/v1/hr/succession-plans');
        $response->assertStatus(403);
    }

    public function test_hr_can_delete_succession_plan(): void
    {
        $position = Position::factory()->create();
        $emp = Employee::factory()->create();
        $plan = new SuccessionPlan();
        $plan->fill([
            'position_id'  => $position->id,
            'successor_id' => $emp->id,
            'readiness'    => SuccessionReadiness::ReadyNow->value,
            'priority'     => SuccessionPriority::Low->value,
            'created_by'   => $this->hrUser->id,
        ]);
        $plan->status = SuccessionStatus::Active;
        $plan->save();

        $response = $this->actingAs($this->hrUser)->deleteJson("/api/v1/hr/succession-plans/{$plan->hash_id}");
        $response->assertStatus(204);
        $this->assertSoftDeleted('succession_plans', ['id' => $plan->id]);
    }
}
