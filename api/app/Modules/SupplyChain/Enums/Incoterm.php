<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

/**
 * International Commercial Terms (Incoterms 2020).
 * Determines freight and insurance responsibility allocation between buyer and seller.
 */
enum Incoterm: string
{
    case EXW = 'EXW'; // Ex Works
    case FCA = 'FCA'; // Free Carrier
    case FAS = 'FAS'; // Free Alongside Ship
    case FOB = 'FOB'; // Free On Board
    case CFR = 'CFR'; // Cost and Freight
    case CIF = 'CIF'; // Cost, Insurance and Freight
    case CPT = 'CPT'; // Carriage Paid To
    case CIP = 'CIP'; // Carriage and Insurance Paid To
    case DAP = 'DAP'; // Delivered at Place
    case DPU = 'DPU'; // Delivered at Place Unloaded
    case DDP = 'DDP'; // Delivered Duty Paid

    /**
     * True when the BUYER arranges freight (the seller's obligation ends at loading).
     * Applies to EXW, FCA, FAS, FOB.
     */
    public function freightPaidByBuyer(): bool
    {
        return in_array($this, [self::EXW, self::FCA, self::FAS, self::FOB], true);
    }

    /**
     * True when the BUYER arranges insurance (seller not obliged to insure the goods in transit).
     * Applies to EXW, FCA, FAS, FOB, CFR, CPT.
     */
    public function insurancePaidByBuyer(): bool
    {
        return in_array($this, [self::EXW, self::FCA, self::FAS, self::FOB, self::CFR, self::CPT], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::EXW => 'Ex Works',
            self::FCA => 'Free Carrier',
            self::FAS => 'Free Alongside Ship',
            self::FOB => 'Free On Board',
            self::CFR => 'Cost and Freight',
            self::CIF => 'Cost, Insurance and Freight',
            self::CPT => 'Carriage Paid To',
            self::CIP => 'Carriage and Insurance Paid To',
            self::DAP => 'Delivered at Place',
            self::DPU => 'Delivered at Place Unloaded',
            self::DDP => 'Delivered Duty Paid',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
