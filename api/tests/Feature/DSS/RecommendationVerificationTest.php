<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\DSS\Contracts\VerificationResult;
use App\Modules\DSS\Detectors\WorkOrderInterventionDetector;
use App\Modules\DSS\Enums\RecommendationEventType;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\DssRunnerService;
use App\Modules\DSS\Services\RecommendationScorer;
use App\Modules\DSS\Verifiers\NcrSlaVerifier;
use App\Modules\DSS\Verifiers\WorkOrderInterventionVerifier;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrSlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RecommendationVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_result_requires_resolution_proof_and_observation_time_only_when_clear(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VerificationResult(VerificationResult::VERIFIED_CLEAR);
    }

    public function test_verification_result_rejects_resolution_evidence_for_other_states(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VerificationResult(VerificationResult::STILL_OPEN, 'Condition remains open.');
    }

    public function test_work_order_verifier_keeps_overdue_source_open_and_proves_inactive_source_clear(): void
    {
        $overdue = WorkOrder::factory()->create([
            'status' => 'in_progress',
            'planned_end' => now()->subDay(),
        ]);
        $verifier = new WorkOrderInterventionVerifier;

        $this->assertSame(
            VerificationResult::STILL_OPEN,
            $verifier->verify($this->recommendation('dss.work_order_intervention', [
                'source_id' => $overdue->id,
                'planned_end' => $overdue->planned_end->toIso8601String(),
            ]))->status,
        );

        $overdue->update(['status' => 'completed']);
        $result = $verifier->verify($this->recommendation('dss.work_order_intervention', [
            'source_id' => $overdue->id,
            'planned_end' => now()->subDay()->toIso8601String(),
        ]));

        $this->assertSame(VerificationResult::VERIFIED_CLEAR, $result->status);
        $this->assertNotNull($result->observedAt);
        $this->assertSame('Work order is no longer active.', $result->proof);
        $this->assertDoesNotMatchRegularExpression('/[0-9\\\\]/', $result->proof);
    }

    public function test_work_order_verifier_returns_unavailable_for_deleted_or_unscoped_source(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => 'confirmed',
            'planned_end' => now()->subDay(),
        ]);
        $workOrder->delete();
        $verifier = new WorkOrderInterventionVerifier;

        $this->assertSame(VerificationResult::UNAVAILABLE, $verifier->verify($this->recommendation(
            'dss.work_order_intervention',
            ['source_id' => $workOrder->id, 'planned_end' => now()->subDay()->toIso8601String()],
        ))->status);
        $this->assertSame(VerificationResult::UNAVAILABLE, $verifier->verify($this->recommendation(
            'dss.work_order_intervention',
            ['source_id' => $workOrder->id],
        ))->status);
    }

    public function test_work_order_verifier_clears_when_planned_end_moves_out_of_overdue_scope(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => 'in_progress',
            'planned_end' => now()->subDay(),
        ]);
        $context = [
            'source_id' => $workOrder->id,
            'planned_end' => $workOrder->planned_end->toIso8601String(),
        ];
        $workOrder->update(['planned_end' => now()->addDay()]);

        $result = (new WorkOrderInterventionVerifier)->verify(
            $this->recommendation('dss.work_order_intervention', $context),
        );

        $this->assertSame(VerificationResult::VERIFIED_CLEAR, $result->status);
        $this->assertSame('Work order deadline is no longer overdue.', $result->proof);
    }

    public function test_complete_runner_scan_resolves_only_after_work_order_verifies_clear(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => 'in_progress',
            'planned_end' => now()->subDay(),
        ]);
        $registry = new DetectorRegistry;
        $registry->register('dss.work_order_intervention', WorkOrderInterventionDetector::class);
        $params = app(DssParameterService::class);
        $runner = new DssRunnerService($registry, $params, new RecommendationScorer($params));

        $firstRun = $runner->run('dss.work_order_intervention');
        $recommendation = DssRecommendation::query()->where('dedupe_key', 'work-order:'.$workOrder->id)->firstOrFail();
        $this->assertSame('completed', $firstRun['detectors']['dss.work_order_intervention']['status']);
        $this->assertSame('open', $recommendation->status);

        $workOrder->update(['status' => 'completed']);
        $secondRun = $runner->run('dss.work_order_intervention');
        $recommendation->refresh();

        $this->assertSame('completed', $secondRun['detectors']['dss.work_order_intervention']['status']);
        $this->assertSame('resolved', $recommendation->status);
        $this->assertSame(VerificationResult::VERIFIED_CLEAR, $recommendation->verification_status);
        $this->assertSame('Work order is no longer active.', $recommendation->resolution_evidence['proof']);
        $this->assertSame('work_order_overdue', $recommendation->resolution_evidence['source_condition']);
        $this->assertSame('work_order_status', $recommendation->resolution_evidence['verifier']);
        $runLog = DssRunLog::query()->latest('id')->firstOrFail();
        $this->assertSame('completed', $runLog->status);
        $this->assertSame($runLog->hash_id, $recommendation->resolution_evidence['run_reference']);
        $this->assertSame($recommendation->source_observed_at->format(DATE_ATOM), $recommendation->resolution_evidence['observed_at']);
        $this->assertSame(
            DssRunLog::query()->where('detector_id', 'dss.work_order_intervention')->latest('id')->value('id'),
            $recommendation->last_evaluated_run_id,
        );
        $this->assertNotNull($recommendation->source_observed_at);
        $this->assertDatabaseHas('dss_recommendation_events', [
            'recommendation_id' => $recommendation->id,
            'event' => RecommendationEventType::Resolved->value,
            'from_status' => 'open',
            'to_status' => 'resolved',
        ]);
    }

    public function test_ncr_verifier_keeps_overdue_response_open_and_clears_closed_source(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('quality.ncr.sla_low_hours', 2);
        $ncr = NonConformanceReport::factory()->create([
            'severity' => 'low',
            'status' => 'open',
            'created_at' => now()->subHours(3),
            'last_escalated_at' => null,
            'escalation_level' => 0,
        ]);
        $verifier = new NcrSlaVerifier(app(NcrSlaService::class));
        $context = [
            'source_id' => $ncr->id,
            'severity' => 'low',
            'sla_hours' => 2,
            'source_clock' => $ncr->created_at->toIso8601String(),
            'source_clock_rule' => 'COALESCE(last_escalated_at, created_at)',
        ];

        $this->assertSame(
            VerificationResult::STILL_OPEN,
            $verifier->verify($this->recommendation('dss.quality_ncr_sla', $context))->status,
        );

        $ncr->update(['status' => 'closed', 'closed_at' => now()]);
        $result = $verifier->verify($this->recommendation('dss.quality_ncr_sla', $context));

        $this->assertSame(VerificationResult::VERIFIED_CLEAR, $result->status);
        $this->assertSame('NCR is no longer open.', $result->proof);
        $this->assertNotNull($result->observedAt);
        $this->assertDoesNotMatchRegularExpression('/[0-9\\\\]/', $result->proof);
    }

    public function test_ncr_verifier_keeps_the_exact_sla_boundary_open(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        app(SettingsService::class)->set('quality.ncr.sla_low_hours', 2);
        $ncr = NonConformanceReport::factory()->create([
            'severity' => 'low',
            'status' => 'open',
            'created_at' => now()->subHours(2),
        ]);
        $context = [
            'source_id' => $ncr->id,
            'severity' => 'low',
            'sla_hours' => 2,
            'source_clock' => $ncr->created_at->toIso8601String(),
            'source_clock_rule' => 'COALESCE(last_escalated_at, created_at)',
        ];

        $result = app(NcrSlaVerifier::class)
            ->verify($this->recommendation('dss.quality_ncr_sla', $context));

        $this->assertSame(VerificationResult::STILL_OPEN, $result->status);
    }

    public function test_ncr_verifier_returns_unavailable_when_sla_scope_changes_or_source_is_missing(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('quality.ncr.sla_low_hours', 2);
        $ncr = NonConformanceReport::factory()->create([
            'severity' => 'low',
            'status' => 'open',
            'created_at' => now()->subHours(3),
        ]);
        $context = [
            'source_id' => $ncr->id,
            'severity' => 'low',
            'sla_hours' => 2,
            'source_clock' => $ncr->created_at->toIso8601String(),
            'source_clock_rule' => 'COALESCE(last_escalated_at, created_at)',
        ];
        $verifier = new NcrSlaVerifier(app(NcrSlaService::class));

        $settings->set('quality.ncr.sla_low_hours', 3);
        $this->assertSame(VerificationResult::UNAVAILABLE, $verifier->verify(
            $this->recommendation('dss.quality_ncr_sla', $context),
        )->status);

        $ncr->update(['severity' => 'medium']);
        $this->assertSame(VerificationResult::UNAVAILABLE, $verifier->verify(
            $this->recommendation('dss.quality_ncr_sla', $context),
        )->status);

        $settings->set('quality.ncr.sla_low_hours', 2);
        $ncr->update(['severity' => 'low']);
        $ncr->update(['last_escalated_at' => now()->addHour()]);
        $this->assertSame(VerificationResult::UNAVAILABLE, $verifier->verify(
            $this->recommendation('dss.quality_ncr_sla', $context),
        )->status);
        $ncr->update(['last_escalated_at' => null]);
        $ncr->update(['escalation_level' => 3]);
        $this->assertSame(VerificationResult::STILL_OPEN, $verifier->verify(
            $this->recommendation('dss.quality_ncr_sla', $context),
        )->status);
        $ncr->delete();
        $this->assertSame(VerificationResult::UNAVAILABLE, $verifier->verify(
            $this->recommendation('dss.quality_ncr_sla', $context),
        )->status);
    }

    private function recommendation(string $detectorId, array $verificationContext): DssRecommendation
    {
        $recommendation = new DssRecommendation;
        $recommendation->detector_id = $detectorId;
        $recommendation->verification_context = $verificationContext;

        return $recommendation;
    }
}
