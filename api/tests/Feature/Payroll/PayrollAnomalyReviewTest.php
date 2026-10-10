<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAnomalyFlag;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollAnomalyReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_anomaly_resolution_is_audited_with_actor_and_before_after_values(): void
    {
        $reviewer = $this->makeUser('hr_officer');
        $flag = $this->makeFlag();
        $remarks = 'Checked first payroll against signed employee file.';

        $this->actingAs($reviewer)
            ->patchJson("/api/v1/payroll-anomalies/{$flag->hash_id}/resolve", ['remarks' => $remarks])
            ->assertOk()
            ->assertJsonPath('data.is_resolved', true)
            ->assertJsonPath('data.resolution_remarks', $remarks)
            ->assertJsonPath('data.resolved_by.name', $reviewer->name);

        $audit = DB::table('audit_logs')
            ->where('action', 'payroll.anomaly.resolve')
            ->where('model_type', PayrollAnomalyFlag::class)
            ->where('model_id', $flag->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($reviewer->id, (int) $audit->user_id);
        $this->assertSame([
            'is_resolved' => false,
            'resolved_by' => null,
            'resolved_at' => null,
            'resolution_remarks' => null,
        ], json_decode($audit->old_values, true));
        $newValues = json_decode($audit->new_values, true);
        $this->assertSame(true, $newValues['is_resolved']);
        $this->assertSame($reviewer->id, $newValues['resolved_by']);
        $this->assertSame($remarks, $newValues['resolution_remarks']);
        $this->assertNotEmpty($newValues['resolved_at']);
    }

    public function test_anomaly_resolution_cannot_be_rewritten_by_a_second_reviewer(): void
    {
        $reviewer = $this->makeUser('hr_officer');
        $otherReviewer = $this->makeUser('hr_officer');
        $flag = $this->makeFlag();
        $remarks = 'Reviewed and verified against signed employee file.';

        $this->actingAs($reviewer)
            ->patchJson("/api/v1/payroll-anomalies/{$flag->hash_id}/resolve", ['remarks' => $remarks])
            ->assertOk();

        $this->actingAs($otherReviewer)
            ->patchJson("/api/v1/payroll-anomalies/{$flag->hash_id}/resolve", ['remarks' => 'Replace first review.'])
            ->assertUnprocessable();

        $flag->refresh();
        $this->assertTrue($flag->is_resolved);
        $this->assertSame((int) $reviewer->id, (int) $flag->resolved_by);
        $this->assertSame($remarks, $flag->resolution_remarks);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'payroll.anomaly.resolve')
            ->where('model_type', PayrollAnomalyFlag::class)
            ->where('model_id', $flag->id)
            ->count());
    }

    public function test_period_makers_cannot_review_their_own_anomaly_flags(): void
    {
        $creator = $this->makeUser('hr_officer');
        $computer = $this->makeUser('hr_officer');

        foreach ([
            [$creator, $computer],
            [$computer, $creator],
        ] as [$maker, $otherMaker]) {
            $flag = $this->makeFlag(
                status: PayrollPeriodStatus::Computed,
                createdBy: $maker,
                computedBy: $otherMaker,
            );

            $this->actingAs($maker)
                ->patchJson("/api/v1/payroll-anomalies/{$flag->hash_id}/resolve", ['remarks' => 'Self-review attempt.'])
                ->assertForbidden();

            $this->assertFalse($flag->fresh()->is_resolved);
            $this->assertSame(0, DB::table('audit_logs')
                ->where('action', 'payroll.anomaly.resolve')
                ->where('model_id', $flag->id)
                ->count());
        }
    }

    public function test_anomalies_cannot_be_reviewed_outside_computed_or_approved_states(): void
    {
        $reviewer = $this->makeUser('hr_officer');

        foreach ([
            PayrollPeriodStatus::Draft,
            PayrollPeriodStatus::Processing,
            PayrollPeriodStatus::Finalized,
            PayrollPeriodStatus::Disbursed,
            PayrollPeriodStatus::Voided,
        ] as $status) {
            $flag = $this->makeFlag(status: $status);

            $this->actingAs($reviewer)
                ->patchJson("/api/v1/payroll-anomalies/{$flag->hash_id}/resolve", ['remarks' => 'Review in the wrong state.'])
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Payroll anomalies can only be reviewed while the period is computed or approved.');

            $this->assertFalse($flag->fresh()->is_resolved);
            $this->assertSame(0, DB::table('audit_logs')
                ->where('action', 'payroll.anomaly.resolve')
                ->where('model_id', $flag->id)
                ->count());
        }
    }

    public function test_only_the_seeded_anomaly_reviewer_can_resolve_flags(): void
    {
        $finance = $this->makeUser('finance_officer');
        $flag = $this->makeFlag();

        $this->actingAs($finance)
            ->patchJson("/api/v1/payroll-anomalies/{$flag->hash_id}/resolve", ['remarks' => 'Reviewed by Finance.'])
            ->assertForbidden();

        $this->assertFalse($flag->fresh()->is_resolved);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('action', 'payroll.anomaly.resolve')
            ->where('model_id', $flag->id)
            ->count());
    }

    private function makeUser(string $roleSlug): User
    {
        return User::create([
            'name' => ucfirst($roleSlug).' reviewer',
            'email' => uniqid($roleSlug.'_').'@audit.test',
            'password' => bcrypt('Password1!'),
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    private function makeFlag(
        PayrollPeriodStatus $status = PayrollPeriodStatus::Computed,
        ?User $createdBy = null,
        ?User $computedBy = null,
    ): PayrollAnomalyFlag
    {
        $period = PayrollPeriod::factory()->create(['status' => $status->value]);
        $period->forceFill([
            'created_by' => $createdBy?->id ?? $period->created_by,
            'computed_by' => $computedBy?->id,
        ])->save();
        $payroll = Payroll::factory()->create(['payroll_period_id' => $period->id]);

        return PayrollAnomalyFlag::create([
            'payroll_id' => $payroll->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $payroll->employee_id,
            'flag_type' => 'first_payroll',
            'details' => ['current_net' => 1200],
            'is_resolved' => false,
        ]);
    }
}
