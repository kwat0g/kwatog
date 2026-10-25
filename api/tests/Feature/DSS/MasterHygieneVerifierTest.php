<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Contracts\VerificationResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Services\DssRunnerService;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Verifiers\MasterHygieneVerifier;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterHygieneVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_stays_open_while_the_named_master_data_rule_matches(): void
    {
        Item::factory()->create([
            'reorder_point' => 0,
            'safety_stock' => 5,
            'lead_time_days' => 7,
            'standard_cost' => 100,
        ]);

        $result = app(MasterHygieneVerifier::class)->verify($this->recommendation('items_missing_reorder_point'));

        $this->assertSame(VerificationResult::STILL_OPEN, $result->status);
    }

    public function test_clears_only_after_the_full_named_source_scope_is_empty(): void
    {
        $item = Item::factory()->create([
            'reorder_point' => 0,
            'safety_stock' => 5,
            'lead_time_days' => 7,
            'standard_cost' => 100,
        ]);
        $recommendation = $this->recommendation('items_missing_reorder_point');
        $verifier = app(MasterHygieneVerifier::class);

        $this->assertSame(VerificationResult::STILL_OPEN, $verifier->verify($recommendation)->status);
        $item->update(['reorder_point' => 10]);

        $result = $verifier->verify($recommendation);

        $this->assertSame(VerificationResult::VERIFIED_CLEAR, $result->status);
        $this->assertSame('No current records match this master-data gap.', $result->proof);
        $this->assertNotNull($result->observedAt);
    }

    public function test_rejects_wrong_detector_unknown_gap_and_mismatched_decision_code(): void
    {
        $verifier = app(MasterHygieneVerifier::class);
        $recommendation = $this->recommendation('items_missing_reorder_point');

        $this->assertSame(
            VerificationResult::UNAVAILABLE,
            $verifier->verify(new DssRecommendation(['detector_id' => 'dss.other']))->status,
        );

        $recommendation->verification_context = ['gap' => 'untrusted_gap'];
        $this->assertSame(VerificationResult::UNAVAILABLE, $verifier->verify($recommendation)->status);

        $recommendation = $this->recommendation('items_missing_reorder_point');
        $recommendation->decision_code = 'master_data_gap.products_without_active_bom';
        $this->assertSame(VerificationResult::UNAVAILABLE, $verifier->verify($recommendation)->status);
    }

    public function test_runner_records_verified_clear_after_source_data_is_corrected(): void
    {
        $item = Item::factory()->create([
            'reorder_point' => 0,
            'safety_stock' => 5,
            'lead_time_days' => 7,
            'standard_cost' => 100,
        ]);
        app(DssParameterService::class)->set('dss.master_hygiene', 'mode', 'advisory');
        $runner = app(DssRunnerService::class);

        $runner->run('dss.master_hygiene');
        $recommendation = DssRecommendation::query()
            ->where('detector_id', 'dss.master_hygiene')
            ->where('dedupe_key', 'hygiene:items_missing_reorder_point')
            ->firstOrFail();

        $item->update(['reorder_point' => 10]);
        $runner->run('dss.master_hygiene');

        $recommendation->refresh();
        $this->assertSame('resolved', $recommendation->status);
        $this->assertSame(VerificationResult::VERIFIED_CLEAR, $recommendation->verification_status);
        $this->assertSame('master_data_gap.items_missing_reorder_point', $recommendation->resolution_evidence['source_condition']);
        $this->assertSame('master_hygiene_source', $recommendation->resolution_evidence['verifier']);
    }

    private function recommendation(string $gap): DssRecommendation
    {
        $recommendation = new DssRecommendation;
        $recommendation->detector_id = 'dss.master_hygiene';
        $recommendation->decision_code = "master_data_gap.{$gap}";
        $recommendation->rationale = ['gap' => $gap];
        $recommendation->verification_context = ['gap' => $gap];

        return $recommendation;
    }
}
