<?php

declare(strict_types=1);

namespace App\Modules\DSS\Enums;

enum RecommendationStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
    case Snoozed = 'snoozed';
    case Expired = 'expired';
    case Executed = 'executed';

    /** States that keep the dedupe key occupied (partial unique index). */
    public function blocksDedupeKey(): bool
    {
        return $this === self::Open || $this === self::Snoozed;
    }

    /** Terminal states: a later re-occurrence opens a fresh row. */
    public function isTerminal(): bool
    {
        return $this === self::Accepted
            || $this === self::Dismissed
            || $this === self::Expired
            || $this === self::Executed;
    }
}
