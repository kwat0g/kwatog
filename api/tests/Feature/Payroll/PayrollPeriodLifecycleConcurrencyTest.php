<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lifecycle transitions must validate the authoritative period row, not the
 * route-bound model that may have gone stale while a user was confirming a
 * newer payroll state in another request.
 */
class PayrollPeriodLifecycleConcurrencyTest extends TestCase
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
        return User::create([
            'name' => 'Payroll Tester '.uniqid(),
            'email' => 'payroll_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id' => Role::query()->orderBy('id')->value('id'),
            'is_active' => true,
        ]);
    }

    private function makePeriod(PayrollPeriodStatus $status): PayrollPeriod
    {
        return PayrollPeriod::factory()->create([
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-15',
            'payroll_date' => '2026-09-15',
            'is_first_half' => true,
            'status' => $status->value,
        ]);
    }

    public function test_stale_approval_cannot_overwrite_a_period_already_approved(): void
    {
        $actor = $this->makeUser();
        $period = $this->makePeriod(PayrollPeriodStatus::Computed);
        Payroll::factory()->create(['payroll_period_id' => $period->id]);
        $stale = $period->fresh();

        $period->forceFill([
            'status' => PayrollPeriodStatus::Approved->value,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ])->save();

        $this->expectException(BusinessRuleException::class);
        app(PayrollPeriodService::class)->approve($stale, $actor);
    }

    public function test_stale_finalization_cannot_resurrect_a_voided_period(): void
    {
        $actor = $this->makeUser();
        $period = $this->makePeriod(PayrollPeriodStatus::Approved);
        $stale = $period->fresh();

        $period->forceFill(['status' => PayrollPeriodStatus::Voided->value])->save();

        try {
            app(PayrollPeriodService::class)->finalize($stale, $actor);
            $this->fail('A stale finalization should be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Only approved periods can be finalized.', $e->getMessage());
        }

        $this->assertSame(
            PayrollPeriodStatus::Voided->value,
            $period->fresh()->status->value,
        );
    }

    public function test_stale_disbursement_cannot_duplicate_a_disbursed_transition(): void
    {
        $actor = $this->makeUser();
        $period = $this->makePeriod(PayrollPeriodStatus::Finalized);
        DisbursementProof::create([
            'payroll_period_id' => $period->id,
            'proof_type' => 'bank_confirmation',
            'file_name' => 'proof.pdf',
            'file_path' => 'proofs/proof.pdf',
            'disbursed_amount' => '100.00',
            'disbursement_date' => '2026-09-15',
            'uploaded_by' => $actor->id,
        ]);
        $stale = $period->fresh();

        $period->forceFill([
            'status' => PayrollPeriodStatus::Disbursed->value,
            'disbursement_status' => 'disbursed',
        ])->save();

        $this->expectException(BusinessRuleException::class);
        app(PayrollPeriodService::class)->markDisbursed($stale, $actor);
    }

    public function test_stale_void_cannot_overwrite_a_period_already_voided(): void
    {
        $actor = $this->makeUser();
        $period = $this->makePeriod(PayrollPeriodStatus::Finalized);
        $stale = $period->fresh();

        $period->forceFill(['status' => PayrollPeriodStatus::Voided->value])->save();

        $this->expectException(BusinessRuleException::class);
        app(PayrollPeriodService::class)->void($stale, $actor, 'duplicate request');
    }
}
