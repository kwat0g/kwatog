<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

enum ReturnCaseStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case InformationNeeded = 'information_needed';
    case ActionAgreed = 'action_agreed';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under Review',
            self::InformationNeeded => 'Information Needed',
            self::ActionAgreed => 'Action Agreed',
            self::InProgress => 'In Progress',
            self::Resolved => 'Resolved',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
