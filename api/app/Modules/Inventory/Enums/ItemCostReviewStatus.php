<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum ItemCostReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
