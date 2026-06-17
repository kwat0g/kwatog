<?php

declare(strict_types=1);

namespace App\Modules\Landing\Enums;

enum QuoteRequestStatus: string
{
    case New = 'new';
    case Reviewed = 'reviewed';
    case Contacted = 'contacted';
    case Closed = 'closed';
}
