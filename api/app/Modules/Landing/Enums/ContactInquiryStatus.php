<?php

declare(strict_types=1);

namespace App\Modules\Landing\Enums;

/**
 * Lifecycle of a public contact-form submission.
 *
 * Deliberately minimal: the inbox only needs to answer "has someone dealt
 * with this yet?"
 */
enum ContactInquiryStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InProgress => 'In progress',
            self::Closed => 'Closed',
        };
    }
}
