<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T3.4.A — Self-service read-only endpoint for training records.
 * Confirms each user only sees their own employee's records.
 */
class SelfServiceTrainingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_sees_only_their_own_training_records(): void
    {
        $dept = Department::firstOrCreate(['code' => 'WHS'], ['name' => 'Warehouse']);

        $empA = Employee::factory()->create(['department_id' => $dept->id]);
        $empB = Employee::factory()->create(['department_id' => $dept->id]);

        $userA = User::factory()->create(['employee_id' => $empA->id]);
        $userB = User::factory()->create(['employee_id' => $empB->id]);

        $training = Training::create([
            'name' => 'Forklift', 'validity_months' => 12, 'is_active' => true,
        ]);

        $recA = EmployeeTraining::create([
            'employee_id'   => $empA->id,
            'training_id'   => $training->id,
            'scheduled_for' => '2026-07-01',
        ]);
        $recA->forceFill(['status' => EmployeeTrainingStatus::Scheduled->value])->save();

        $recB = EmployeeTraining::create([
            'employee_id'   => $empB->id,
            'training_id'   => $training->id,
            'scheduled_for' => '2026-07-15',
        ]);
        $recB->forceFill(['status' => EmployeeTrainingStatus::Scheduled->value])->save();

        $resp = $this->actingAs($userA)->getJson('/api/v1/hr/self-service/trainings');

        $resp->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($recA->hash_id, $resp->json('data.0.id'));
        $this->assertSame('2026-07-01', $resp->json('data.0.scheduled_for'));

        // user B's record id MUST NOT appear in user A's response.
        $ids = collect($resp->json('data'))->pluck('id')->all();
        $this->assertNotContains($recB->hash_id, $ids);
    }

    public function test_user_with_no_linked_employee_is_rejected(): void
    {
        $user = User::factory()->create(['employee_id' => null]);

        $this->actingAs($user)
            ->getJson('/api/v1/hr/self-service/trainings')
            ->assertStatus(403);
    }
}
