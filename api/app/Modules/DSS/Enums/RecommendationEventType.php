<?php

declare(strict_types=1);

namespace App\Modules\DSS\Enums;

enum RecommendationEventType: string
{
    case Viewed = 'viewed';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';
    case Snoozed = 'snoozed';
    case Expired = 'expired';
    case Executed = 'executed';
    case Outcome = 'outcome';
    case Started = 'started';
    case Resolved = 'resolved';
}
