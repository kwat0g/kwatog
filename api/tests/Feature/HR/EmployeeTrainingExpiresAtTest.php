<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Exceptions\BusinessRuleException;
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

    public function test_retake_creates_a_new_record_that_starts_clean(): void
    {
        // Recertification policy (2026-09-04, Option B): a completed record is
        // immutable — re-completing it is refused, and a retake is a NEW
        // assignment record. The fresh record carries no alert bookkeeping, so
        // future expiry firings run off its own expires_at; the superseded row
        // keeps its signed-off history for traceability.
        $dept = Department::firstOrCreate(['code' => 'WHS'], ['name' => 'Warehouse']);
        $emp  = Employee::factory()->create(['department_id' => $dept->id]);
        $t    = Training::create([
            'name' => 'Forklift', 'validity_months' => 6, 'is_active' => true,
        ]);

        /** @var EmployeeTrainingService $svc */
        $svc = app(EmployeeTrainingService::class);

        $original = EmployeeTraining::create([
            'employee_id' => $emp->id, 'training_id' => $t->id,
            'scheduled_for' => '2025-12-01',
        ]);
        $svc->recordCompletion($original, Carbon::parse('2026-01-01')); // expires 2026-07-01

        // The expiry cron already fired the T14 tier for the original.
        $original->refresh()->forceFill([
            'last_alert_level' => TrainingAlertLevel::T14->value,
            'last_alert_at'    => now(),
        ])->save();

        // Re-completing the signed-off record is refused, not a silent reset.
        try {
            $svc->recordCompletion($original->fresh(), Carbon::parse('2026-07-01'));
            $this->fail('Re-completing a completed record must be refused.');
        } catch (BusinessRuleException) {
            // expected — a completed record is terminal.
        }

        // The retake is a fresh assignment record.
        $retake = EmployeeTraining::create([
            'employee_id' => $emp->id, 'training_id' => $t->id,
            'scheduled_for' => '2026-06-01',
        ]);
        $retake = $svc->recordCompletion($retake, Carbon::parse('2026-07-01'));

        // The original keeps its signed-off history untouched.
        $original->refresh();
        $this->assertSame('2026-01-01', $original->completed_at->toDateString());
        $this->assertSame('2026-07-01', $original->expires_at->toDateString());
        $this->assertSame(TrainingAlertLevel::T14, $original->last_alert_level);
        $this->assertSame(EmployeeTrainingStatus::Completed, $original->status);

        // The fresh record starts clean and carries its own expiry.
        $this->assertNull($retake->last_alert_level);
        $this->assertNull($retake->last_alert_at);
        $this->assertSame('2027-01-01', $retake->expires_at->toDateString());
    }
}
