<?php

declare(strict_types=1);

namespace App\Modules\B2B\Enums;

enum SupplierShippingDocumentType: string
{
    case CommercialInvoice = 'commercial_invoice';
    case PackingList = 'packing_list';
    case BillOfLading = 'bill_of_lading';
    case CertificateOfAnalysis = 'certificate_of_analysis';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CommercialInvoice => 'Commercial invoice',
            self::PackingList => 'Packing list',
            self::BillOfLading => 'Bill of lading',
            self::CertificateOfAnalysis => 'Certificate of analysis (CoA)',
            self::Other => 'Other',
        };
    }
}
