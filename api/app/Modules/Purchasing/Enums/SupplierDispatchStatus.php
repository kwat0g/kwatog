<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum SupplierDispatchStatus: string
{
    case Pending = 'pending';
    case PortalAvailable = 'portal_available';
    case ManualRequired = 'manual_required';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Preparing dispatch',
            self::PortalAvailable => 'Available in supplier portal',
            self::ManualRequired => 'Manual transmission required',
            self::Confirmed => 'Transmission confirmed',
            self::Failed => 'Dispatch failed',
            self::Cancelled => 'Dispatch cancelled with purchase order',
        };
    }
}
