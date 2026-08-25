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
}
