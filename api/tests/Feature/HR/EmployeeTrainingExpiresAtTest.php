<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Enums\TrainingAlertLevel;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use App\Modules\HR\Services\EmployeeTrainingService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTrainingExpiresAtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeRecord(?int $validityMonths): EmployeeTraining
    {
        $dept = Department::firstOrCreate(['code' => 'WHS'], ['name' => 'Warehouse']);
        $emp  = Employee::factory()->create(['department_id' => $dept->id]);
        $t    = Training::create([
            'name' => 'Forklift', 'validity_months' => $validityMonths, 'is_active' => true,
        ]);
        return EmployeeTraining::create([
            'employee_id' => $emp->id, 'training_id' => $t->id,
            'scheduled_for' => '2026-06-01',
        ]);
    }

    public function test_expires_at_is_computed_from_validity_months(): void
    {
        $rec = $this->makeRecord(12);

        /** @var EmployeeTrainingService $svc */
        $svc = app(EmployeeTrainingService::class);
        $rec = $svc->recordCompletion($rec, Carbon::parse('2026-06-15'));

        $this->assertSame('2026-06-15', $rec->completed_at->toDateString());
        $this->assertSame('2027-06-15', $rec->expires_at->toDateString());
        $this->assertSame(EmployeeTrainingStatus::Completed, $rec->status);
    }

    public function test_null_validity_yields_null_expires_at(): void
    {
        $rec = $this->makeRecord(null);

        /** @var EmployeeTrainingService $svc */
        $svc = app(EmployeeTrainingService::class);
        $rec = $svc->recordCompletion($rec, Carbon::parse('2026-06-15'));

        $this->assertSame('2026-06-15', $rec->completed_at->toDateString());
        $this->assertNull($rec->expires_at);
    }

    public function test_recompletion_resets_alert_state(): void
    {
        $rec = $this->makeRecord(6);

        /** @var EmployeeTrainingService $svc */
        $svc = app(EmployeeTrainingService::class);
        $svc->recordCompletion($rec, Carbon::parse('2026-01-01'));

        // Simulate that the cron fired t14 already.
        $rec->refresh()->forceFill([
            'last_alert_level' => TrainingAlertLevel::T14->value,
            'last_alert_at'    => now(),
        ])->save();

        // Re-complete (e.g. retake): expect alert state cleared so future
        // expiry firings can re-fire on the new expires_at.
        $svc->recordCompletion($rec->fresh(), Carbon::parse('2026-07-01'));

        $rec->refresh();
        $this->assertNull($rec->last_alert_level);
        $this->assertNull($rec->last_alert_at);
        $this->assertSame('2027-01-01', $rec->expires_at->toDateString());
    }
}
