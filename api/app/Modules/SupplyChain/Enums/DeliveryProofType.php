<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum DeliveryProofType: string
{
    case SignedDeliveryReceipt = 'signed_dr';
    case Photo = 'photo';
    case CustomerPoConfirmation = 'customer_po_confirmation';
    case CertificateOfConformance = 'coc';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SignedDeliveryReceipt => 'Signed delivery receipt',
            self::Photo => 'Photo',
            self::CustomerPoConfirmation => 'Customer PO confirmation',
            self::CertificateOfConformance => 'Certificate of conformance',
            self::Other => 'Other',
        };
    }
}
