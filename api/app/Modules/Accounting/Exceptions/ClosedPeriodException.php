<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;

/**
 * OGAMI-001 — thrown when a posting/back-dating attempt targets a date that
 * falls inside an accounting period whose status is `closed`.
 */
class ClosedPeriodException extends RuntimeException
{
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly string $date,
    ) {
        parent::__construct(sprintf(
            'Accounting period %04d-%02d is closed. Cannot post or back-date a transaction dated %s. '
            . 'Reopen the period first or post to the next open period.',
            $year,
            $month,
            $date,
        ));
    }
}
