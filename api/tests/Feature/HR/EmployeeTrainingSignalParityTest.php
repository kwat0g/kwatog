<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTrainingSignalParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_training_badges_match_scoped_filtered_lists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00', 'Asia/Manila'));
        try {
            $department = Department::factory()->create();
            $otherDepartment = Department::factory()->create();
            $headEmployee = Employee::factory()->create(['department_id' => $department->id]);
            $head = User::factory()->create([
                'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
                'employee_id' => $headEmployee->id,
            ]);
            $sameDepartmentEmployee = Employee::factory()->create(['department_id' => $department->id]);
            $otherDepartmentEmployee = Employee::factory()->create(['department_id' => $otherDepartment->id]);
            $inactiveEmployee = Employee::factory()->create([
                'department_id' => $department->id,
                'status' => EmployeeStatus::Resigned->value,
            ]);

            $this->trainingRecord($sameDepartmentEmployee, 'Expired safety', EmployeeTrainingStatus::Expired, expiresAt: '2026-10-25');
            $this->trainingRecord($otherDepartmentEmployee, 'Other department expired', EmployeeTrainingStatus::Expired, expiresAt: '2026-10-25');
            $this->trainingRecord($inactiveEmployee, 'Former employee expired', EmployeeTrainingStatus::Expired, expiresAt: '2026-10-25');
            $this->trainingRecord($sameDepartmentEmployee, 'Training at boundary', EmployeeTrainingStatus::Scheduled, scheduledFor: '2026-11-09');
            $this->trainingRecord($sameDepartmentEmployee, 'Training outside window', EmployeeTrainingStatus::Scheduled, scheduledFor: '2026-11-10');
            $this->trainingRecord($sameDepartmentEmployee, 'Certification at boundary', EmployeeTrainingStatus::Completed, completedAt: '2026-10-01', expiresAt: '2026-11-25');
            $this->trainingRecord($sameDepartmentEmployee, 'Cancelled training', EmployeeTrainingStatus::Cancelled, scheduledFor: '2026-10-27');

            $retake = Training::create(['name' => 'Retaken certification', 'validity_months' => 12, 'is_active' => true]);
            $this->trainingRecord($sameDepartmentEmployee, $retake, EmployeeTrainingStatus::Completed, scheduledFor: '2026-01-01', completedAt: '2026-01-02', expiresAt: '2026-11-15');
            $this->trainingRecord($sameDepartmentEmployee, $retake, EmployeeTrainingStatus::Completed, scheduledFor: '2026-10-01', completedAt: '2026-10-02', expiresAt: '2027-10-02');
            $this->trainingRecord($sameDepartmentEmployee, $retake, EmployeeTrainingStatus::Expired, scheduledFor: '2025-01-01', completedAt: '2025-01-02', expiresAt: '2026-01-02');

            $expired = $this->actingAs($head, 'sanctum')
                ->getJson('/api/v1/hr/employee-trainings?signal=expired')
                ->assertOk();
            $upcoming = $this->actingAs($head, 'sanctum')
                ->getJson('/api/v1/hr/employee-trainings?signal=upcoming')
                ->assertOk();
            $badges = $this->actingAs($head, 'sanctum')
                ->getJson('/api/v1/dashboards/badges')
                ->assertOk()
                ->json('data');

            $this->assertSame(1, $expired->json('meta.total'));
            $this->assertSame($expired->json('meta.total'), $badges['training_expiry']['count']);
            $this->assertSame(2, $upcoming->json('meta.total'));
            $this->assertSame($upcoming->json('meta.total'), $badges['training_upcoming']['count']);
            $this->assertSame(['expired'], $expired->json('data.*.status'));
            $this->assertSame(['completed', 'scheduled'], $upcoming->json('data.*.status'));

            $hrOfficer = User::factory()->create([
                'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
            ]);
            $hrExpired = $this->actingAs($hrOfficer, 'sanctum')
                ->getJson('/api/v1/hr/employee-trainings?signal=expired')
                ->assertOk();
            $hrUpcoming = $this->actingAs($hrOfficer, 'sanctum')
                ->getJson('/api/v1/hr/employee-trainings?signal=upcoming')
                ->assertOk();
            $hrBadges = $this->actingAs($hrOfficer, 'sanctum')
                ->getJson('/api/v1/dashboards/badges')
                ->assertOk()
                ->json('data');
            $this->assertSame(2, $hrExpired->json('meta.total'));
            $this->assertSame($hrExpired->json('meta.total'), $hrBadges['training_expiry']['count']);
            $this->assertSame(2, $hrUpcoming->json('meta.total'));
            $this->assertSame($hrUpcoming->json('meta.total'), $hrBadges['training_upcoming']['count']);

            $sameDepartmentEmployee->forceFill(['status' => EmployeeStatus::Resigned])->save();
            $refreshedBadges = $this->actingAs($head, 'sanctum')
                ->getJson('/api/v1/dashboards/badges')
                ->assertOk()
                ->json('data');
            $this->assertSame(0, $refreshedBadges['training_expiry']['count']);
            $this->assertSame(0, $refreshedBadges['training_upcoming']['count']);
            $refreshedHrBadges = $this->actingAs($hrOfficer, 'sanctum')
                ->getJson('/api/v1/dashboards/badges')
                ->assertOk()
                ->json('data');
            $this->assertSame(1, $refreshedHrBadges['training_expiry']['count']);
            $this->assertSame(0, $refreshedHrBadges['training_upcoming']['count']);

            $unrelatedUser = User::factory()->create([
                'role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id'),
            ]);
            $this->actingAs($unrelatedUser, 'sanctum')
                ->getJson('/api/v1/hr/employee-trainings?signal=expired')
                ->assertForbidden();
            $unrelatedBadges = $this->actingAs($unrelatedUser, 'sanctum')
                ->getJson('/api/v1/dashboards/badges')
                ->assertOk()
                ->json('data');
            $this->assertArrayNotHasKey('training_expiry', $unrelatedBadges);
            $this->assertArrayNotHasKey('training_upcoming', $unrelatedBadges);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function trainingRecord(
        Employee $employee,
        string|Training $training,
        EmployeeTrainingStatus $status,
        ?string $scheduledFor = null,
        ?string $completedAt = null,
        ?string $expiresAt = null,
    ): EmployeeTraining {
        $training = is_string($training)
            ? Training::create(['name' => $training, 'validity_months' => 12, 'is_active' => true])
            : $training;
        $record = EmployeeTraining::create([
            'employee_id' => $employee->id,
            'training_id' => $training->id,
            'scheduled_for' => $scheduledFor,
            'completed_at' => $completedAt,
            'expires_at' => $expiresAt,
        ]);
        $record->forceFill(['status' => $status])->save();

        return $record;
    }
}
