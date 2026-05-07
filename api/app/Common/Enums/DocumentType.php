<?php

declare(strict_types=1);

namespace App\Common\Enums;

/**
 * Series E (Task E1/E3) — every kind of generated document the vault tracks.
 * Adding a new type? Update this enum AND the per-type permission key
 * resolver in DocumentVaultService::permissionFor().
 */
enum DocumentType: string
{
    case Payslip            = 'payslip';
    case Invoice            = 'invoice';
    case PurchaseOrder      = 'purchase_order';
    case PurchaseRequest    = 'purchase_request';
    case Bill               = 'bill';
    case JournalEntry       = 'journal_entry';
    case Coc                = 'coc';
    case Complaint8D        = 'complaint_8d';
    case PayrollRegister    = 'payroll_register';
    case BalanceSheet       = 'balance_sheet';
    case IncomeStatement    = 'income_statement';
    case TrialBalance       = 'trial_balance';
    case SssR3              = 'sss_r3';
    case PhilHealthRf1      = 'philhealth_rf1';
    case PagIbigRemittance  = 'pagibig_remittance';
    case Bir1601c           = 'bir_1601c';
    case Bir2316            = 'bir_2316';
    case Ncr                = 'ncr';
    case WorkOrderTraveler  = 'work_order_traveler';
    case BulkPdf            = 'bulk_pdf';

    /** Confidential by default — affects watermark + Cache-Control. */
    public function isConfidential(): bool
    {
        return match ($this) {
            self::Payslip,
            self::PayrollRegister,
            self::Bir1601c,
            self::Bir2316,
            self::SssR3,
            self::PhilHealthRf1,
            self::PagIbigRemittance => true,
            default                 => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Payslip            => 'Payslip',
            self::Invoice            => 'Invoice',
            self::PurchaseOrder      => 'Purchase Order',
            self::PurchaseRequest    => 'Purchase Request',
            self::Bill               => 'Bill',
            self::JournalEntry       => 'Journal Entry',
            self::Coc                => 'Certificate of Conformance',
            self::Complaint8D        => '8D Report',
            self::PayrollRegister    => 'Payroll Register',
            self::BalanceSheet       => 'Balance Sheet',
            self::IncomeStatement    => 'Income Statement',
            self::TrialBalance       => 'Trial Balance',
            self::SssR3              => 'SSS R-3',
            self::PhilHealthRf1      => 'PhilHealth RF-1',
            self::PagIbigRemittance  => 'Pag-IBIG Remittance',
            self::Bir1601c           => 'BIR 1601-C',
            self::Bir2316            => 'BIR 2316',
            self::Ncr                => 'NCR',
            self::WorkOrderTraveler  => 'Work Order Traveler',
            self::BulkPdf            => 'Bulk PDF',
        };
    }
}
