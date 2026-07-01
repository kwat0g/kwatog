<?php

declare(strict_types=1);

namespace Tests\Unit\Quality;

use App\Modules\Quality\Enums\SpcAlertRule;
use App\Modules\Quality\Services\SpcService;
use Tests\TestCase;

class SpcRunRulesTest extends TestCase
{
    private SpcService $spc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spc = app(SpcService::class);
    }

    public function test_rule_1_beyond_three_sigma(): void
    {
        // UCL=30, LCL=10, center=20
        // index 0 (current) = 31.0 which is > UCL → triggers Rule 1
        $violations = $this->spc->evaluateRunRulesFromValues(
            recentMeans: [31.0, 20.0, 19.5, 20.5],
            centerLine: 20.0,
            ucl: 30.0,
            lcl: 10.0
        );

        $this->assertContains(SpcAlertRule::BeyondThreeSigma, $violations);
    }

    public function test_rule_4_eight_same_side(): void
    {
        $violations = $this->spc->evaluateRunRulesFromValues(
            recentMeans: [21.0, 21.5, 22.0, 21.0, 20.5, 21.0, 22.0, 21.5],
            centerLine: 20.0,
            ucl: 30.0,
            lcl: 10.0
        );

        $this->assertContains(SpcAlertRule::EightSameSide, $violations);
    }

    public function test_no_violations_for_normal_data(): void
    {
        $violations = $this->spc->evaluateRunRulesFromValues(
            recentMeans: [20.1, 19.8, 20.3, 19.9, 20.0],
            centerLine: 20.0,
            ucl: 30.0,
            lcl: 10.0
        );

        $this->assertEmpty($violations);
    }

    public function test_rule_2_two_of_three_beyond_two_sigma(): void
    {
        // σ = (30-20)/3 = 3.333, 2σ upper = 26.667
        // indices 0,1,2 checked: 27.0 and 28.0 are > 26.667 → 2 of 3 above
        $violations = $this->spc->evaluateRunRulesFromValues(
            recentMeans: [27.0, 19.0, 28.0],
            centerLine: 20.0,
            ucl: 30.0,
            lcl: 10.0
        );

        $this->assertContains(SpcAlertRule::TwoOfThreeBeyondTwoSigma, $violations);
    }

    public function test_rule_3_four_of_five_beyond_one_sigma(): void
    {
        // 1σ zone boundary: 16.67 and 23.33
        $violations = $this->spc->evaluateRunRulesFromValues(
            recentMeans: [24.0, 25.0, 20.0, 24.0, 25.0],
            centerLine: 20.0,
            ucl: 30.0,
            lcl: 10.0
        );

        $this->assertContains(SpcAlertRule::FourOfFiveBeyondOneSigma, $violations);
    }
}
