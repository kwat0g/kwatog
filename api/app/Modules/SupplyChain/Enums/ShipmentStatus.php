<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

/** Sprint 7 — Task 65. Shipment status flow for imported POs. */
enum ShipmentStatus: string
{
    case Ordered   = 'ordered';
    case Shipped   = 'shipped';
    case InTransit = 'in_transit';
    case Customs   = 'customs';
    case Cleared   = 'cleared';
    case Received  = 'received';
    case Cancelled = 'cancelled';

    /** Allowed forward transitions (cancellation is allowed from any non-terminal). */
    public function canTransitionTo(self $next): bool
    {
        if ($next === self::Cancelled) return $this !== self::Received && $this !== self::Cancelled;
        return match ($this) {
            self::Ordered   => $next === self::Shipped,
            self::Shipped   => $next === self::InTransit,
            self::InTransit => $next === self::Customs,
            self::Customs   => $next === self::Cleared,
            self::Cleared   => $next === self::Received,
            default         => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Received, self::Cancelled], true);
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
