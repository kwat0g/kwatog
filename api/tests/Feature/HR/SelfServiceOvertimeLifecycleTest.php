<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfServiceOvertimeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makePending(Employee $employee): OvertimeRequest
    {
        return OvertimeRequest::factory()->create([
            'employee_id' => $employee->id,
            'date' => now()->subDay()->toDateString(),
            'status' => OvertimeStatus::Pending->value,
        ]);
    }

    public function test_self_service_cancellation_uses_the_canonical_service_and_records_provenance(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $ot = $this->makePending($employee);

        $this->actingAs($user)
            ->deleteJson("/api/v1/hr/self-service/overtime/{$ot->hash_id}")
            ->assertOk()
            ->assertJsonPath('message', 'Overtime request cancelled.');

        $cancelled = $ot->fresh();
        $this->assertSame(OvertimeStatus::Rejected, $cancelled->status);
        $this->assertSame($user->id, (int) $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNull($cancelled->approved_by);
        $this->assertNull($cancelled->approved_at);
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => OvertimeRequestDecided::class,
        ]);
    }

    public function test_self_service_cannot_restore_an_approver_rejection(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $approver = User::factory()->create();
        $ot = OvertimeRequest::factory()->create([
            'employee_id' => $employee->id,
            'status' => OvertimeStatus::Rejected->value,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => 'Staffing coverage is not available.',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/v1/hr/self-service/overtime/{$ot->hash_id}/restore")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Only your own cancelled overtime request can be restored.']);

        $unchanged = $ot->fresh();
        $this->assertSame(OvertimeStatus::Rejected, $unchanged->status);
        $this->assertNull($unchanged->cancelled_at);
        $this->assertSame('Staffing coverage is not available.', $unchanged->rejection_reason);
        $this->assertDatabaseMissing('event_outbox', [
            'event_type' => OvertimeRequestSubmitted::class,
        ]);
    }

    public function test_employee_can_restore_their_own_cancelled_request_and_resubmit_it(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $ot = $this->makePending($employee);

        $this->actingAs($user)
            ->deleteJson("/api/v1/hr/self-service/overtime/{$ot->hash_id}")
            ->assertOk();

        $this->actingAs($user)
            ->patchJson("/api/v1/hr/self-service/overtime/{$ot->hash_id}/restore")
            ->assertOk()
            ->assertJsonPath('message', 'Overtime request restored.');

        $restored = $ot->fresh();
        $this->assertSame(OvertimeStatus::Pending, $restored->status);
        $this->assertNull($restored->cancelled_by);
        $this->assertNull($restored->cancelled_at);
        $this->assertNull($restored->rejection_reason);
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => OvertimeRequestSubmitted::class,
        ]);
    }

    public function test_another_employee_cannot_restore_a_cancelled_request(): void
    {
        $employee = Employee::factory()->create();
        $owner = User::factory()->create(['employee_id' => $employee->id]);
        $otherEmployee = Employee::factory()->create();
        $other = User::factory()->create(['employee_id' => $otherEmployee->id]);
        $ot = $this->makePending($employee);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/hr/self-service/overtime/{$ot->hash_id}")
            ->assertOk();

        $this->actingAs($other)
            ->patchJson("/api/v1/hr/self-service/overtime/{$ot->hash_id}/restore")
            ->assertNotFound();

        $this->assertSame(OvertimeStatus::Rejected, $ot->fresh()->status);
    }
}
