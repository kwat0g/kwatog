<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

/** Sprint 7 — Task 66. Outbound delivery lifecycle. */
enum DeliveryStatus: string
{
    case Scheduled = 'scheduled';
    case Loading   = 'loading';
    case InTransit = 'in_transit';
    case ReturnPending = 'return_pending';
    case Delivered = 'delivered';
    case Confirmed = 'confirmed';
    case Returned  = 'returned';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        if ($next === self::Cancelled) return in_array($this, [self::Scheduled, self::Loading], true);
        return match ($this) {
            self::Scheduled => $next === self::Loading,
            self::Loading   => $next === self::InTransit,
            self::InTransit => in_array($next, [self::Delivered, self::ReturnPending], true),
            self::ReturnPending => in_array($next, [self::Delivered, self::Returned], true),
            self::Delivered => $next === self::Confirmed,
            default         => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Confirmed, self::Returned, self::Cancelled], true);
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Statuses a driver may report from the mobile delivery workflow. */
    public static function driverValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            [self::Loading, self::InTransit, self::Delivered],
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Loading => 'Loading',
            self::InTransit => 'In transit',
            self::ReturnPending => 'Truck return pending',
            self::Delivered => 'Delivered',
            self::Confirmed => 'Confirmed',
            self::Returned => 'Returned to depot',
            self::Cancelled => 'Cancelled',
        };
    }
}
