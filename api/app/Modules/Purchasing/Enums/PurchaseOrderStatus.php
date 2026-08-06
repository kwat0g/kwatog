<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum PurchaseOrderStatus: string
{
    case Draft              = 'draft';
    case PendingApproval    = 'pending_approval';
    case Approved           = 'approved';
    case Sent               = 'sent';
    case PartiallyReceived  = 'partially_received';
    case Received           = 'received';
    case Closed             = 'closed';
    case Cancelled          = 'cancelled';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Sent => 'Sent',
            self::PartiallyReceived => 'Partially received',
            self::Received => 'Received',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }
}
