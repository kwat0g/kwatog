<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum SpcAlertRule: string
{
    case BeyondThreeSigma = 'rule_1_beyond_3sigma';
    case TwoOfThreeBeyondTwoSigma = 'rule_2_two_of_three_beyond_2sigma';
    case FourOfFiveBeyondOneSigma = 'rule_3_four_of_five_beyond_1sigma';
    case EightSameSide = 'rule_4_eight_same_side';
}
