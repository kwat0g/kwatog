<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

enum ReturnRequestStatus: string
{
    case Draft           = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Received        = 'received';
    case Inspected       = 'inspected';
    case Completed       = 'completed';
    case Rejected        = 'rejected';
    case Cancelled       = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft           => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved        => 'Approved',
            self::Received        => 'Received',
            self::Inspected       => 'Inspected',
            self::Completed       => 'Completed',
            self::Rejected        => 'Rejected',
            self::Cancelled       => 'Cancelled',
        };
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Completed, self::Rejected, self::Cancelled], true);
    }
}
