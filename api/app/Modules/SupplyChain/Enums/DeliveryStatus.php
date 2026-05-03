<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

/** Sprint 7 — Task 66. Outbound delivery lifecycle. */
enum DeliveryStatus: string
{
    case Scheduled = 'scheduled';
    case Loading   = 'loading';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        if ($next === self::Cancelled) return $this !== self::Confirmed && $this !== self::Cancelled;
        return match ($this) {
            self::Scheduled => $next === self::Loading,
            self::Loading   => $next === self::InTransit,
            self::InTransit => $next === self::Delivered,
            self::Delivered => $next === self::Confirmed,
            default         => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Confirmed, self::Cancelled], true);
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
