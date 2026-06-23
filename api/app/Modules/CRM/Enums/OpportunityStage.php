<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum OpportunityStage: string
{
    case Prospecting    = 'prospecting';
    case NeedsAnalysis  = 'needs_analysis';
    case Proposal       = 'proposal';
    case Negotiation    = 'negotiation';
    case Won            = 'won';
    case Lost           = 'lost';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Prospecting   => 'Prospecting',
            self::NeedsAnalysis => 'Needs Analysis',
            self::Proposal      => 'Proposal',
            self::Negotiation   => 'Negotiation',
            self::Won           => 'Won',
            self::Lost          => 'Lost',
        };
    }

    /** Ordered stages for advancement (excludes terminal states). */
    public static function advanceOrder(): array
    {
        return [
            self::Prospecting,
            self::NeedsAnalysis,
            self::Proposal,
            self::Negotiation,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Won, self::Lost], true);
    }
}
