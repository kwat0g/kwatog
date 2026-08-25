<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Common\Services\SettingsService;
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

    public function test_history_limit_keeps_scheduled_training_and_caps_old_records(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $training = Training::create([
            'name' => 'Safety orientation', 'validity_months' => 12, 'is_active' => true,
        ]);
        app(SettingsService::class)->set('self_service.history_limit', 1);

        foreach (['2026-08-01', '2026-08-02'] as $date) {
            $row = EmployeeTraining::create([
                'employee_id' => $employee->id,
                'training_id' => $training->id,
                'scheduled_for' => $date,
            ]);
            $row->forceFill(['status' => EmployeeTrainingStatus::Scheduled->value])->save();
        }
        foreach (['2026-07-01', '2026-07-02'] as $date) {
            $row = EmployeeTraining::create([
                'employee_id' => $employee->id,
                'training_id' => $training->id,
                'scheduled_for' => $date,
            ]);
            $row->forceFill(['status' => EmployeeTrainingStatus::Completed->value])->save();
        }

        $this->actingAs($user)
            ->getJson('/api/v1/hr/self-service/trainings')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.status', EmployeeTrainingStatus::Scheduled->value)
            ->assertJsonPath('data.1.status', EmployeeTrainingStatus::Scheduled->value);
    }
}
