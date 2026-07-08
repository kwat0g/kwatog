<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollPeriodVoided;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * REC-01 — POST /payroll-periods/{period}/void.
 *
 * Exposes the pre-existing PayrollPeriodService::void() (OGAMI-011) over HTTP:
 * only a Finalized period can be voided, a reason is mandatory, the actor is
 * recorded, an audit row is written, and the PayrollPeriodVoided event fires.
 * SoD: finance_officer (finalizer) may void; hr_officer (compute/approve only)
 * may not.
 */
class PayrollPeriodVoidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
        ]);
    }

    public function test_finance_officer_can_void_a_finalized_period(): void
    {
        Event::fake([PayrollPeriodVoided::class]);

        $finance = $this->userWithRole('finance_officer');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Finalized->value,
        ]);

        $this->actingAs($finance)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/void", [
                'reason' => 'Backdated OT for E. Cruz was missing; recomputing this half.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', PayrollPeriodStatus::Voided->value);

        $fresh = $period->fresh();
        $this->assertSame(PayrollPeriodStatus::Voided, $fresh->status);
        $this->assertSame($finance->id, $fresh->voided_by);
        $this->assertNotNull($fresh->voided_at);
        $this->assertStringContainsString('Backdated OT', (string) $fresh->void_reason);

        $this->assertDatabaseHas('audit_logs', [
            'action'     => 'payroll.period.void',
            'model_type' => PayrollPeriod::class,
            'model_id'   => $period->id,
            'user_id'    => $finance->id,
        ]);

        Event::assertDispatched(PayrollPeriodVoided::class);
    }

    public function test_void_requires_a_reason(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Finalized->value,
        ]);

        $this->actingAs($finance)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/void", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // Also rejects a too-short reason.
        $this->actingAs($finance)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/void", ['reason' => 'oops'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(PayrollPeriodStatus::Finalized, $period->fresh()->status);
    }

    public function test_rejects_void_when_status_is_draft(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Draft->value,
        ]);

        $this->actingAs($finance)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/void", [
                'reason' => 'trying to void a draft period',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only finalized periods can be voided.');

        $this->assertSame(PayrollPeriodStatus::Draft, $period->fresh()->status);
    }

    public function test_rejects_void_when_status_is_disbursed(): void
    {
        $finance = $this->userWithRole('finance_officer');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Disbursed->value,
        ]);

        $this->actingAs($finance)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/void", [
                'reason' => 'salaries already paid out',
            ])
            ->assertStatus(422);

        $this->assertSame(PayrollPeriodStatus::Disbursed, $period->fresh()->status);
    }

    /**
     * SoD: hr_officer computes/approves but does NOT finalize or void. The
     * seeder grants hr_officer an explicit payroll list that omits
     * payroll.periods.void, so the guard must return 403.
     */
    public function test_hr_officer_cannot_void_a_period(): void
    {
        $hr = $this->userWithRole('hr_officer');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Finalized->value,
        ]);

        $this->actingAs($hr)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/void", [
                'reason' => 'hr officer should not be allowed to void',
            ])
            ->assertStatus(403);

        $this->assertSame(PayrollPeriodStatus::Finalized, $period->fresh()->status);
    }

    public function test_rejects_unauthenticated_and_unprivileged_users(): void
    {
        $employee = $this->userWithRole('employee');
        $period = PayrollPeriod::factory()->create([
            'status' => PayrollPeriodStatus::Finalized->value,
        ]);

        $this->actingAs($employee)
            ->postJson("/api/v1/payroll-periods/{$period->hash_id}/void", [
                'reason' => 'employee should never reach this',
            ])
            ->assertStatus(403);

        $this->assertSame(PayrollPeriodStatus::Finalized, $period->fresh()->status);
    }
}
