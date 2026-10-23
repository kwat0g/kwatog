<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\DSS\Services\RecommendationScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationScorerTest extends TestCase
{
    use RefreshDatabase;

    private function scorer(): RecommendationScorer
    {
        return new RecommendationScorer(app(DssParameterService::class));
    }

    private function emission(array $overrides = []): DetectorEmission
    {
        return new DetectorEmission(
            type: 't',
            severity: $overrides['severity'] ?? 'warning',
            dedupeKey: 'k',
            rationale: ['rule' => 'x'],
            confidence: $overrides['confidence'] ?? null,
            impactEstimate: $overrides['impactEstimate'] ?? null,
            expiresAt: $overrides['expiresAt'] ?? null,
            priorityScore: $overrides['priorityScore'] ?? null,
        );
    }

    public function test_critical_confident_urgent_outranks_info(): void
    {
        $high = $this->scorer()->score($this->emission([
            'severity' => 'critical', 'confidence' => 0.9,
            'impactEstimate' => '5000.00', 'expiresAt' => new \DateTimeImmutable('+6 hours'),
        ]));
        $low = $this->scorer()->score($this->emission(['severity' => 'info']));

        $this->assertGreaterThan($low, $high);
        $this->assertLessThanOrEqual(1.0, $high);
    }

    public function test_preset_score_is_kept(): void
    {
        $this->assertSame(0.4244, $this->scorer()->score($this->emission(['priorityScore' => 0.42444])));
    }

    public function test_missing_confidence_defaults_to_half(): void
    {
        $without = $this->scorer()->score($this->emission(['severity' => 'warning']));
        $with = $this->scorer()->score($this->emission(['severity' => 'warning', 'confidence' => 0.5]));

        $this->assertSame($with, $without);
    }
}
