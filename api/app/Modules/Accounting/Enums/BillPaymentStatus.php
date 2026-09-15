<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum BillPaymentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Rejected = 'rejected';
    case Posted = 'posted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Pending Approval',
            self::Rejected => 'Rejected',
            self::Posted => 'Posted',
            self::Voided => 'Voided',
        };
    }
}
