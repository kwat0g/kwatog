<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollPeriodDisbursed;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * P3.4 + P3.5 — Event correctness tests.
 *
 * P3.4: finalize() fires PayrollPeriodFinalized exactly once and NEVER
 *       PayrollPeriodDisbursed; markDisbursed() fires PayrollPeriodDisbursed
 *       exactly once and does NOT fire a second PayrollPeriodFinalized.
 *
 * P3.5: finalize() still sets the correct status after the transaction wrap,
 *       and the event is still dispatched exactly once.
 */
class PayrollPeriodEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
    }

    private function makeUser(): User
    {
        $roleId = Role::query()->orderBy('id')->value('id');
        return User::create([
            'name'     => 'Tester ' . uniqid(),
            'email'    => 't_' . uniqid() . '@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    /** Build an Approved period ready to be finalized. */
    private function makeApprovedPeriod(): PayrollPeriod
    {
        $period = PayrollPeriod::factory()->create([
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-15',
            'payroll_date' => '2026-07-15',
            'is_first_half' => true,
            'status'       => PayrollPeriodStatus::Approved->value,
        ]);
        return $period;
    }

    /** Build a Finalized period with one disbursement proof attached. */
    private function makeFinalizedPeriodWithProof(User $uploader): PayrollPeriod
    {
        $period = PayrollPeriod::factory()->create([
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-15',
            'payroll_date' => '2026-08-15',
            'is_first_half' => true,
            'status'       => PayrollPeriodStatus::Finalized->value,
        ]);

        DisbursementProof::create([
            'payroll_period_id'    => $period->id,
            'proof_type'           => 'bank_confirmation',
            'file_name'            => 'proof.pdf',
            'file_path'            => 'proofs/proof.pdf',
            'disbursed_amount'     => '100000.00',
            'disbursement_date'    => '2026-08-15',
            'uploaded_by'          => $uploader->id,
        ]);

        return $period;
    }

    // ─── P3.4 + P3.5: finalize() ─────────────────────────────────────────

    public function test_finalize_dispatches_PayrollPeriodFinalized_exactly_once(): void
    {
        Event::fake([PayrollPeriodFinalized::class, PayrollPeriodDisbursed::class]);

        /** @var PayrollPeriodService $svc */
        $svc    = app(PayrollPeriodService::class);
        $period = $this->makeApprovedPeriod();

        $svc->finalize($period);

        Event::assertDispatched(PayrollPeriodFinalized::class, 1);
    }

    public function test_finalize_does_not_dispatch_PayrollPeriodDisbursed(): void
    {
        Event::fake([PayrollPeriodFinalized::class, PayrollPeriodDisbursed::class]);

        /** @var PayrollPeriodService $svc */
        $svc    = app(PayrollPeriodService::class);
        $period = $this->makeApprovedPeriod();

        $svc->finalize($period);

        Event::assertNotDispatched(PayrollPeriodDisbursed::class);
    }

    /** P3.5: transaction wrap must not break the status mutation. */
    public function test_finalize_sets_finalized_status_after_transaction_wrap(): void
    {
        Event::fake([PayrollPeriodFinalized::class, PayrollPeriodDisbursed::class]);

        /** @var PayrollPeriodService $svc */
        $svc    = app(PayrollPeriodService::class);
        $period = $this->makeApprovedPeriod();

        $result = $svc->finalize($period);

        $this->assertSame(PayrollPeriodStatus::Finalized, $result->status);
        $this->assertDatabaseHas('payroll_periods', [
            'id'     => $period->id,
            'status' => PayrollPeriodStatus::Finalized->value,
        ]);
    }

    /** P3.5: the event carries the now-finalized period (post-commit). */
    public function test_finalize_event_carries_finalized_period(): void
    {
        Event::fake([PayrollPeriodFinalized::class, PayrollPeriodDisbursed::class]);

        /** @var PayrollPeriodService $svc */
        $svc    = app(PayrollPeriodService::class);
        $period = $this->makeApprovedPeriod();

        $svc->finalize($period);

        Event::assertDispatched(
            PayrollPeriodFinalized::class,
            fn (PayrollPeriodFinalized $e) => $e->period->id === $period->id
                && $e->period->status === PayrollPeriodStatus::Finalized,
        );
    }

    // ─── P3.4: markDisbursed() ───────────────────────────────────────────

    public function test_markDisbursed_dispatches_PayrollPeriodDisbursed_exactly_once(): void
    {
        Event::fake([PayrollPeriodFinalized::class, PayrollPeriodDisbursed::class]);

        /** @var PayrollPeriodService $svc */
        $svc    = app(PayrollPeriodService::class);
        $user   = $this->makeUser();
        $period = $this->makeFinalizedPeriodWithProof($user);

        $svc->markDisbursed($period, $user);

        Event::assertDispatched(PayrollPeriodDisbursed::class, 1);
    }

    public function test_markDisbursed_does_not_dispatch_PayrollPeriodFinalized(): void
    {
        Event::fake([PayrollPeriodFinalized::class, PayrollPeriodDisbursed::class]);

        /** @var PayrollPeriodService $svc */
        $svc    = app(PayrollPeriodService::class);
        $user   = $this->makeUser();
        $period = $this->makeFinalizedPeriodWithProof($user);

        $svc->markDisbursed($period, $user);

        Event::assertNotDispatched(PayrollPeriodFinalized::class);
    }

    /** Sanity: markDisbursed still sets the correct status. */
    public function test_markDisbursed_sets_disbursed_status(): void
    {
        Event::fake([PayrollPeriodFinalized::class, PayrollPeriodDisbursed::class]);

        /** @var PayrollPeriodService $svc */
        $svc    = app(PayrollPeriodService::class);
        $user   = $this->makeUser();
        $period = $this->makeFinalizedPeriodWithProof($user);

        $result = $svc->markDisbursed($period, $user);

        $this->assertSame(PayrollPeriodStatus::Disbursed, $result->status);
        $this->assertDatabaseHas('payroll_periods', [
            'id'     => $period->id,
            'status' => PayrollPeriodStatus::Disbursed->value,
        ]);
    }
}
