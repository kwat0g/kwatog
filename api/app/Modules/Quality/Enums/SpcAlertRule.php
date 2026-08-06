<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum SpcAlertRule: string
{
    case BeyondThreeSigma = 'rule_1_beyond_3sigma';
    case TwoOfThreeBeyondTwoSigma = 'rule_2_two_of_three_beyond_2sigma';
    case FourOfFiveBeyondOneSigma = 'rule_3_four_of_five_beyond_1sigma';
    case EightSameSide = 'rule_4_eight_same_side';

    public function label(): string
    {
        return match ($this) {
            self::BeyondThreeSigma => 'Rule 1: Point beyond 3-sigma',
            self::TwoOfThreeBeyondTwoSigma => 'Rule 2: 2 of 3 beyond 2-sigma',
            self::FourOfFiveBeyondOneSigma => 'Rule 3: 4 of 5 beyond 1-sigma',
            self::EightSameSide => 'Rule 4: 8 consecutive on same side',
        };
    }
}
