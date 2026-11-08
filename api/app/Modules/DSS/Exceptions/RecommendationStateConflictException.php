<?php

declare(strict_types=1);

namespace App\Modules\DSS\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** The action was submitted against a recommendation version that has changed. */
final class RecommendationStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'dss_recommendation_state_conflict';
    }

    public function errorKey(): string
    {
        return 'version';
    }
}
