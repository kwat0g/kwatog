<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Common\Services\SettingsService;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Enums\TrainingAlertLevel;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use App\Modules\HR\Models\TrainingExpiryAlertDelivery;
use App\Modules\HR\Services\TrainingExpiryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrainingExpiryAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function seedHrOfficer(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
        ]);
    }

    /** @return array{0: Employee, 1: Training} */
    private function setupEmpAndTraining(): array
    {
        $dept = Department::firstOrCreate(['code' => 'WHS'], ['name' => 'Warehouse']);
        $emp  = Employee::factory()->create(['department_id' => $dept->id]);
        $t    = Training::create([
            'name' => 'Forklift', 'validity_months' => 12, 'is_active' => true,
        ]);
        return [$emp, $t];
    }

    private function makeCompleted(Employee $emp, Training $t, string $expiresAt): EmployeeTraining
    {
        $rec = EmployeeTraining::create([
            'employee_id'   => $emp->id,
            'training_id'   => $t->id,
            'scheduled_for' => '2025-06-01',
            'completed_at'  => '2025-06-01',
            'expires_at'    => $expiresAt,
        ]);
        $rec->forceFill(['status' => EmployeeTrainingStatus::Completed->value])->save();
        return $rec;
    }

    public function test_no_rows_no_alerts(): void
    {
        /** @var TrainingExpiryService $svc */
        $svc = app(TrainingExpiryService::class);
        $r = $svc->check();
        $this->assertSame(0, $r['evaluated']);
        $this->assertSame(0, $r['alerts_sent']);
    }

    public function test_t30_fires_when_30_days_out(): void
    {
        $this->seedHrOfficer();
        [$emp, $t] = $this->setupEmpAndTraining();
        $this->makeCompleted($emp, $t, now()->addDays(30)->toDateString());

        /** @var TrainingExpiryService $svc */
        $svc = app(TrainingExpiryService::class);
        $r = $svc->check();

        $this->assertSame(1, $r['alerts_sent']);
        $rec = EmployeeTraining::query()->first();
        $this->assertSame(TrainingAlertLevel::T30, $rec->last_alert_level);
        $this->assertSame(EmployeeTrainingStatus::Completed, $rec->status);
    }

    public function test_backfilled_record_does_not_hide_newer_completion_by_id(): void
    {
        $this->seedHrOfficer();
        [$emp, $t] = $this->setupEmpAndTraining();
        $newerCompletion = $this->makeCompleted($emp, $t, now()->addDays(30)->toDateString());
        $newerCompletion->forceFill(['completed_at' => '2026-01-01'])->save();
        $backfilled = EmployeeTraining::create([
            'employee_id' => $emp->id,
            'training_id' => $t->id,
            'scheduled_for' => '2025-07-01',
            'completed_at' => '2025-01-01',
            'expires_at' => now()->addDays(14)->toDateString(),
        ]);
        $backfilled->forceFill(['completed_at' => '2025-01-01'])->save();

        $result = app(TrainingExpiryService::class)->check();

        $this->assertSame(1, $result['evaluated']);
        $this->assertSame(0, (int) $backfilled->refresh()->last_alert_level?->ordinal());
        $this->assertNotNull($newerCompletion->refresh()->last_alert_level);
    }

    public function test_idempotent_same_day_rerun(): void
    {
        $this->seedHrOfficer();
        [$emp, $t] = $this->setupEmpAndTraining();
        $this->makeCompleted($emp, $t, now()->addDays(30)->toDateString());

        /** @var TrainingExpiryService $svc */
        $svc = app(TrainingExpiryService::class);
        $first  = $svc->check();
        $second = $svc->check();

        $this->assertSame(1, $first['alerts_sent']);
        $this->assertSame(0, $second['alerts_sent']);
    }

    public function test_t14_upgrade_fires_after_t30(): void
    {
        $this->seedHrOfficer();
        [$emp, $t] = $this->setupEmpAndTraining();
        $rec = $this->makeCompleted($emp, $t, now()->addDays(14)->toDateString());
        $rec->forceFill(['last_alert_level' => TrainingAlertLevel::T30->value])->save();

        /** @var TrainingExpiryService $svc */
        $svc = app(TrainingExpiryService::class);
        $r = $svc->check();

        $this->assertSame(1, $r['alerts_sent']);
        $this->assertSame(TrainingAlertLevel::T14, $rec->refresh()->last_alert_level);
    }

    public function test_expired_tier_marks_status_expired(): void
    {
        $this->seedHrOfficer();
        [$emp, $t] = $this->setupEmpAndTraining();
        $rec = $this->makeCompleted($emp, $t, now()->subDay()->toDateString());

        /** @var TrainingExpiryService $svc */
        $svc = app(TrainingExpiryService::class);
        $r = $svc->check();

        $this->assertSame(1, $r['alerts_sent']);
        $this->assertSame(1, $r['expired_marked']);
        $rec->refresh();
        $this->assertSame(TrainingAlertLevel::Expired, $rec->last_alert_level);
        $this->assertSame(EmployeeTrainingStatus::Expired, $rec->status);
    }

    public function test_smoke_command_runs(): void
    {
        $exit = Artisan::call('training:check-expiries');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Training expiry check:', Artisan::output());
    }

    public function test_empty_recipient_set_does_not_advance_alert_marker_or_expire_record(): void
    {
        [$emp, $training] = $this->setupEmpAndTraining();
        $record = $this->makeCompleted($emp, $training, now()->subDay()->toDateString());
        app(SettingsService::class)->set('hr.training_expiry.notification_roles', []);

        /** @var TrainingExpiryService $service */
        $service = app(TrainingExpiryService::class);
        $result = $service->check();

        $this->assertSame(0, $result['alerts_sent']);
        $this->assertSame(0, $result['expired_marked']);
        $this->assertNull($record->refresh()->last_alert_level);
        $this->assertSame(EmployeeTrainingStatus::Completed, $record->status);
        $this->assertSame(0, TrainingExpiryAlertDelivery::query()->count());
    }

    public function test_all_disabled_channels_do_not_advance_alert_marker(): void
    {
        $hr = $this->seedHrOfficer();
        [$emp, $training] = $this->setupEmpAndTraining();
        $record = $this->makeCompleted($emp, $training, now()->addDays(30)->toDateString());
        app(SettingsService::class)->set('hr.training_expiry.notification_roles', ['hr_officer']);
        DB::table('notification_preferences')->insert([
            ['user_id' => $hr->id, 'notification_type' => 'training.expiry', 'channel' => 'in_app', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $hr->id, 'notification_type' => 'training.expiry', 'channel' => 'email', 'enabled' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        /** @var TrainingExpiryService $service */
        $service = app(TrainingExpiryService::class);
        $result = $service->check();

        $this->assertSame(0, $result['alerts_sent']);
        $this->assertNull($record->refresh()->last_alert_level);
        $this->assertDatabaseCount('training_expiry_alert_deliveries', 0);
    }

    public function test_delivery_claim_is_persisted_with_the_alert_marker(): void
    {
        $hr = $this->seedHrOfficer();
        [$emp, $training] = $this->setupEmpAndTraining();
        $record = $this->makeCompleted($emp, $training, now()->addDays(30)->toDateString());
        app(SettingsService::class)->set('hr.training_expiry.notification_roles', ['hr_officer']);

        /** @var TrainingExpiryService $service */
        $service = app(TrainingExpiryService::class);
        $result = $service->check();

        $this->assertSame(1, $result['alerts_sent']);
        $this->assertDatabaseHas('training_expiry_alert_deliveries', [
            'employee_training_id' => $record->id,
            'alert_level' => TrainingAlertLevel::T30->value,
            'recipient_user_id' => $hr->id,
            'channel' => 'in_app',
            'status' => 'delivered',
        ]);
    }

    public function test_training_expiry_notice_respects_employee_access_and_uses_safe_links(): void
    {
        $eligibleHr = $this->seedHrOfficer();
        $revokedHr = $this->seedHrOfficer();
        $actor = User::factory()->withRole('system_admin')->create();
        [$employee, $training] = $this->setupEmpAndTraining();
        $employeeUser = User::factory()->create(['employee_id' => $employee->id]);
        $record = $this->makeCompleted($employee, $training, now()->addDays(30)->toDateString());

        DB::table('user_permission_overrides')->insert([
            'user_id' => $revokedHr->id,
            'permission_id' => DB::table('permissions')->where('slug', 'hr.employees.trainings.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $actor->id,
            'reason' => 'Training expiry visibility regression test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(SettingsService::class)->set('hr.training_expiry.notification_roles', ['hr_officer']);

        app(TrainingExpiryService::class)->check();

        $eligibleNotification = DB::table('notifications')
            ->where('notifiable_id', $eligibleHr->id)
            ->where('type', 'training.expiry')
            ->first();
        $employeeNotification = DB::table('notifications')
            ->where('notifiable_id', $employeeUser->id)
            ->where('type', 'training.expiry')
            ->first();

        $this->assertNotNull($eligibleNotification);
        $eligibleData = json_decode($eligibleNotification->data, true);
        $this->assertSame("/hr/employee-trainings/{$record->hash_id}", $eligibleData['link_to']);
        $this->assertSame(EmployeeTraining::class, $eligibleData['subject_type']);
        $this->assertSame((string) $record->id, $eligibleData['subject_id']);
        $this->assertNotNull($employeeNotification);
        $employeeData = json_decode($employeeNotification->data, true);
        $this->assertSame("/self-service/trainings/{$record->hash_id}", $employeeData['link_to']);
        $this->assertSame(EmployeeTraining::class, $employeeData['subject_type']);
        $this->assertSame((string) $record->id, $employeeData['subject_id']);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $revokedHr->id,
            'type' => 'training.expiry',
        ]);

        $this->actingAs($eligibleHr)
            ->getJson("/api/v1/hr/employee-trainings/{$record->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $record->hash_id);
        $this->actingAs($employeeUser)
            ->getJson("/api/v1/hr/self-service/trainings/{$record->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $record->hash_id);

        $otherEmployee = Employee::factory()->create();
        $otherUser = User::factory()->create(['employee_id' => $otherEmployee->id]);
        $this->actingAs($otherUser)
            ->getJson("/api/v1/hr/self-service/trainings/{$record->hash_id}")
            ->assertNotFound();
        $this->getJson("/api/v1/hr/employee-trainings/{$record->hash_id}")
            ->assertForbidden();
    }

    public function test_training_expiry_notice_only_reaches_department_heads_with_employee_scope(): void
    {
        [$employee, $training] = $this->setupEmpAndTraining();
        $otherDepartment = Department::firstOrCreate(['code' => 'QA'], ['name' => 'Quality']);
        $departmentHeadRoleId = Role::query()->where('slug', 'department_head')->value('id');
        $inScopeEmployee = Employee::factory()->create(['department_id' => $employee->department_id]);
        $outOfScopeEmployee = Employee::factory()->create(['department_id' => $otherDepartment->id]);
        $inScopeHead = User::factory()->create([
            'role_id' => $departmentHeadRoleId,
            'employee_id' => $inScopeEmployee->id,
        ]);
        $outOfScopeHead = User::factory()->create([
            'role_id' => $departmentHeadRoleId,
            'employee_id' => $outOfScopeEmployee->id,
        ]);
        $record = $this->makeCompleted($employee, $training, now()->addDays(30)->toDateString());
        app(SettingsService::class)->set('hr.training_expiry.notification_roles', ['department_head']);

        app(TrainingExpiryService::class)->check();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $inScopeHead->id,
            'type' => 'training.expiry',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $outOfScopeHead->id,
            'type' => 'training.expiry',
        ]);

        $this->actingAs($inScopeHead)
            ->getJson("/api/v1/hr/employee-trainings/{$record->hash_id}")
            ->assertOk();
        $this->actingAs($outOfScopeHead)
            ->getJson("/api/v1/hr/employee-trainings/{$record->hash_id}")
            ->assertNotFound();
    }
}
