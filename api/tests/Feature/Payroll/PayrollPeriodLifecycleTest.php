<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Services\OutboxDispatcher;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\BankFileGenerationStatus;
use App\Modules\Payroll\Enums\PayrollAnomalyType;
use App\Modules\Payroll\Enums\PayrollGlHandoffStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Events\PayrollGlPostingRequested;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAnomalyFlag;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollAnomalyService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PayrollPeriodLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
    }

    private function makeUser(?string $roleSlug = null): User
    {
        $roleId = $roleSlug === null
            ? Role::query()->orderBy('id')->value('id')
            : Role::query()->where('slug', $roleSlug)->value('id');
        $this->assertNotNull($roleId, "Role [{$roleSlug}] must be seeded for this lifecycle test.");

        return User::create([
            'name' => 'Tester '.uniqid(),
            'email' => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id' => $roleId,
        ]);
    }

    public function test_create_then_overlap_rejected(): void
    {
        /** @var PayrollPeriodService $svc */
        $svc = app(PayrollPeriodService::class);
        $user = $this->makeUser();

        $svc->create([
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15',
            'is_first_half' => true,
        ], $user);

        $this->expectException(\RuntimeException::class);
        $svc->create([
            'period_start' => '2026-04-10',
            'period_end' => '2026-04-25',
            'payroll_date' => '2026-04-25',
            'is_first_half' => true,
        ], $user);
    }

    public function test_cannot_finalize_a_draft_period(): void
    {
        /** @var PayrollPeriodService $svc */
        $svc = app(PayrollPeriodService::class);
        $user = $this->makeUser();

        $period = $svc->create([
            'period_start' => '2026-04-01', 'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15', 'is_first_half' => true,
        ], $user);

        $this->expectException(\RuntimeException::class);
        $svc->finalize($period, $user);
    }

    public function test_approve_then_finalize_sets_correct_status(): void
    {
        /** @var PayrollPeriodService $svc */
        $svc = app(PayrollPeriodService::class);
        $maker = $this->makeUser('hr_officer');
        $checker = $this->makeUser('finance_officer');
        $finalizer = $this->makeUser('finance_officer');

        $eligibleEmployee = Employee::factory()->create([
            'date_hired' => '2025-01-01',
            'basic_monthly_salary' => 20000,
        ]);
        $futureHire = Employee::factory()->create([
            'date_hired' => '2026-06-01',
        ]);

        $period = $svc->create([
            'period_start' => '2026-05-01', 'period_end' => '2026-05-15',
            'payroll_date' => '2026-05-15', 'is_first_half' => true,
        ], $maker);

        $queueManager = Queue::getFacadeRoot();
        Queue::fake();
        $claimed = $svc->claimForComputeAndStage($period, $maker);
        $this->assertSame(PayrollPeriodStatus::Processing, $claimed->status);
        $this->assertSame($maker->id, $claimed->computed_by);
        $this->assertSame(0, Payroll::query()->where('payroll_period_id', $period->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $maker->id,
            'action' => 'updated',
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
        ]);

        $computeOutbox = DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->first();
        $this->assertNotNull($computeOutbox);
        Queue::assertPushed(
            DispatchOutboxMessage::class,
            fn (DispatchOutboxMessage $job): bool => $job->outboxId === $computeOutbox->id,
        );
        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $computeOutbox->id,
            'chain' => 'h2r',
            'entity_type' => 'payroll_period',
            'entity_id' => $period->id,
            'step' => 'compute',
            'status' => 'pending',
        ]);

        // Run the persisted request through the real outbox dispatcher. The
        // Sync queue executes the registered compute listener and payroll job.
        Queue::swap($queueManager);
        (new DispatchOutboxMessage((string) $computeOutbox->id))
            ->handle(app(OutboxDispatcher::class));

        $computed = $period->fresh();
        $payrolls = Payroll::query()->where('payroll_period_id', $period->id)->get();
        $this->assertSame(PayrollPeriodStatus::Computed, $computed->status);
        $this->assertSame($maker->id, $computed->computed_by);
        $this->assertNull($computed->processing_token);
        $this->assertSame([$eligibleEmployee->id], $payrolls->pluck('employee_id')->all());
        $this->assertNotNull($payrolls->sole()->computed_at);
        $this->assertNull($payrolls->sole()->error_message);
        $this->assertDatabaseHas('event_outbox', [
            'id' => $computeOutbox->id,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $computeOutbox->id,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
            'source_command' => 'payroll.compute.release',
        ]);
        $this->assertDatabaseMissing('payrolls', [
            'payroll_period_id' => $period->id,
            'employee_id' => $futureHire->id,
        ]);

        $anomaly = PayrollAnomalyFlag::query()
            ->where('payroll_period_id', $period->id)
            ->sole();
        $this->assertSame(PayrollAnomalyType::FirstPayroll, $anomaly->flag_type);
        $this->assertFalse($anomaly->is_resolved);
        $reviewer = $this->makeUser('hr_officer');
        $resolvedAnomaly = app(PayrollAnomalyService::class)->resolve(
            $anomaly,
            $reviewer->id,
            'Verified first payroll against the signed employee file.',
        );
        $this->assertTrue($resolvedAnomaly->is_resolved);
        $this->assertSame($reviewer->id, $resolvedAnomaly->resolved_by);
        $this->assertSame(0, app(PayrollAnomalyService::class)->unresolvedCount($period->id));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $reviewer->id,
            'action' => 'payroll.anomaly.resolve',
            'model_type' => PayrollAnomalyFlag::class,
            'model_id' => $anomaly->id,
        ]);

        $approved = $svc->approve($computed, $checker);
        $this->assertSame(PayrollPeriodStatus::Approved, $approved->status);
        $this->assertSame($checker->id, $approved->approved_by);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $checker->id,
            'action' => 'payroll.period.approve',
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
        ]);

        $finalized = $svc->finalize($approved, $finalizer);
        $this->assertSame(PayrollPeriodStatus::Finalized, $finalized->status);
        $this->assertSame($finalizer->id, $finalized->finalized_by);
        $this->assertSame(BankFileGenerationStatus::Pending, $finalized->bank_file_status);
        $this->assertSame(PayrollGlHandoffStatus::Pending, $finalized->gl_handoff_status);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $finalizer->id,
            'action' => 'payroll.period.finalize',
            'model_type' => PayrollPeriod::class,
            'model_id' => $period->id,
        ]);
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => PayrollPeriodFinalized::class,
        ]);
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => PayrollGlPostingRequested::class,
        ]);
    }
}
