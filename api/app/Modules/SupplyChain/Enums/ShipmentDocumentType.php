<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

/** Sprint 7 — Task 65. The 9 import document types we track per shipment. */
enum ShipmentDocumentType: string
{
    case ProformaInvoice       = 'proforma_invoice';
    case CommercialInvoice     = 'commercial_invoice';
    case PackingList           = 'packing_list';
    case BillOfLading          = 'bill_of_lading';
    case ImportEntry           = 'import_entry';
    case CertificateOfOrigin   = 'certificate_of_origin';
    case Msds                  = 'msds';
    case BocRelease            = 'boc_release';
    case InsuranceCertificate  = 'insurance_certificate';

    public function label(): string
    {
        return match ($this) {
            self::ProformaInvoice      => 'Proforma invoice',
            self::CommercialInvoice    => 'Commercial invoice',
            self::PackingList          => 'Packing list',
            self::BillOfLading         => 'Bill of lading (B/L)',
            self::ImportEntry          => 'Import entry',
            self::CertificateOfOrigin  => 'Certificate of origin',
            self::Msds                 => 'MSDS',
            self::BocRelease           => 'BOC release',
            self::InsuranceCertificate => 'Insurance certificate',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
