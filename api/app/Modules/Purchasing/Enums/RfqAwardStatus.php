<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum RfqAwardStatus: string
{
    case Awarded = 'awarded';
    case Cancelled = 'cancelled';
}
