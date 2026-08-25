<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Services\ComplaintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintLifecycleTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_reloads_authoritative_status_for_a_stale_caller(): void
    {
        $by = User::factory()->create();
        $complaint = app(ComplaintService::class)->create([
            'customer_id' => Customer::factory()->create()->id,
            'received_date' => now()->toDateString(),
            'severity' => 'medium',
            'description' => 'Lifecycle transition authority test',
            'affected_quantity' => 1,
        ], $by);
        $this->completeQuality($complaint);
        $stale = $complaint->fresh();

        $resolved = app(ComplaintService::class)->resolve($stale);

        $this->assertSame(ComplaintStatus::Resolved, $resolved->status);
        $this->assertNotNull($resolved->resolved_at);

        // The caller still holds the pre-resolve model, but close() must use
        // the locked database row and allow the valid resolved → closed edge.
        $closed = app(ComplaintService::class)->close($stale);

        $this->assertSame(ComplaintStatus::Closed, $closed->status);
        $this->assertNotNull($closed->closed_at);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot resolve from status closed');

        app(ComplaintService::class)->resolve($stale);
    }

    public function test_resolve_requires_a_finalized_8d_report(): void
    {
        $by = User::factory()->create();
        $complaint = app(ComplaintService::class)->create([
            'customer_id' => Customer::factory()->create()->id,
            'received_date' => now()->toDateString(),
            'severity' => 'medium',
            'description' => 'Completion gate test',
            'affected_quantity' => 1,
        ], $by);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('8D report is finalised');

        app(ComplaintService::class)->resolve($complaint->fresh());
    }

    public function test_resolve_requires_a_closed_dispositioned_ncr(): void
    {
        $by = User::factory()->create();
        $complaint = app(ComplaintService::class)->create([
            'customer_id' => Customer::factory()->create()->id,
            'received_date' => now()->toDateString(),
            'severity' => 'medium',
            'description' => 'NCR completion gate test',
            'affected_quantity' => 1,
        ], $by);
        $this->finalizeReportWithoutClosingNcr($complaint);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('linked NCR is closed with a disposition');

        app(ComplaintService::class)->resolve($complaint->fresh());
    }

    private function completeQuality(CustomerComplaint $complaint): void
    {
        $this->finalizeReportWithoutClosingNcr($complaint);
        $fresh = $complaint->fresh(['ncr']);
        $this->assertNotNull($fresh?->ncr);
        $fresh->ncr->forceFill([
            'status' => 'closed',
            'disposition' => 'use_as_is',
        ])->save();
    }

    private function finalizeReportWithoutClosingNcr(CustomerComplaint $complaint): void
    {
        $fresh = $complaint->fresh(['eightDReport']);
        $this->assertNotNull($fresh?->eightDReport);
        $fresh->eightDReport->forceFill([
            'd1_team' => 'Quality team',
            'd2_problem' => 'Reported defect',
            'd3_containment' => 'Quarantine affected stock',
            'd4_root_cause' => 'Verified root cause',
            'd5_corrective_action' => 'Apply corrective action',
            'd6_verification' => 'Verify the corrective action',
            'd7_prevention' => 'Update the control plan',
            'd8_recognition' => 'Recognize the team',
            'finalized_at' => now(),
            'finalized_by' => User::query()->firstOrFail()->id,
        ])->save();
    }
}
