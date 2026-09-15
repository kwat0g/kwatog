<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum RfqComplianceStatus: string
{
    case Pending = 'pending';
    case Compliant = 'compliant';
    case Exception = 'exception';
    case Blocking = 'blocking';
}
