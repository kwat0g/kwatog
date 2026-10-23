<?php

declare(strict_types=1);

namespace App\Modules\DSS\Contracts;

use App\Modules\DSS\Models\DssRecommendation;

interface RecommendationVerifierInterface
{
    public function verify(DssRecommendation $recommendation): VerificationResult;
}
