<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum SalesOrderSubmissionSource: string
{
    case Internal        = 'internal';
    case CustomerPortal  = 'customer_portal';

    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
