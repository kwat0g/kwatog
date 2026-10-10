<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum PriceRequestStatus: string
{
    case Requested = 'requested';
    case PendingFinance = 'pending_finance';
    case Offered = 'offered';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
}
