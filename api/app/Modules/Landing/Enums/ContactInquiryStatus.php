<?php

declare(strict_types=1);

namespace App\Modules\Landing\Enums;

/**
 * Lifecycle of a public contact-form submission.
 *
 * Deliberately shorter than the quoting workflow it replaces: the inbox only
 * needs to answer "has someone dealt with this, and did it become a lead?"
 * `Converted` is set by the convert-to-lead action, never by hand.
 */
enum ContactInquiryStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Converted = 'converted';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InProgress => 'In progress',
            self::Converted => 'Converted',
            self::Closed => 'Closed',
        };
    }
}
