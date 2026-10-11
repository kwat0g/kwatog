<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum SalesOrderStatus: string
{
    case Draft               = 'draft';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case DeliveryNegotiation  = 'delivery_negotiation';
    case Confirmed           = 'confirmed';
    case InProduction        = 'in_production';
    case PartiallyDelivered  = 'partially_delivered';
    case Delivered           = 'delivered';
    case Invoiced            = 'invoiced';
    case Paid                = 'paid';
    case Closed              = 'closed';
    case Cancelled           = 'cancelled';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function isBeforeConfirmation(): bool
    {
        return in_array($this, [self::Draft, self::AwaitingConfirmation, self::DeliveryNegotiation], true);
    }

    /**
     * The statuses past which an order is no longer part of the live order book.
     *
     * `Paid` counts as finished for a pipeline count: the money is in, so
     * nothing is owed action. It stays distinct from `Closed` because the two
     * are separate milestones, and callers that care about the difference read
     * the status itself.
     *
     * Derived from the enum rather than written as a literal `whereNotIn` at the
     * call site, so a future status has to make a decision here instead of
     * silently inheriting "open" by omission.
     *
     * @return list<string>
     */
    public static function terminalForPipeline(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            [self::Paid, self::Closed, self::Cancelled],
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft              => 'Draft',
            self::AwaitingConfirmation => 'Awaiting confirmation',
            self::DeliveryNegotiation => 'Delivery negotiation',
            self::Confirmed          => 'Confirmed',
            self::InProduction       => 'In Production',
            self::PartiallyDelivered => 'Partially Delivered',
            self::Delivered          => 'Delivered',
            self::Invoiced           => 'Invoiced',
            self::Paid               => 'Paid',
            self::Closed             => 'Closed',
            self::Cancelled          => 'Cancelled',
        };
    }
}
