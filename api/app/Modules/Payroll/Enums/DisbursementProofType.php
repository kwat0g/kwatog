<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum DisbursementProofType: string
{
    case DepositSlip = 'deposit_slip';
    case BankConfirmation = 'bank_confirmation';
    case TransferReceipt = 'transfer_receipt';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DepositSlip => 'Deposit Slip',
            self::BankConfirmation => 'Bank Confirmation',
            self::TransferReceipt => 'Transfer Receipt',
            self::Other => 'Other',
        };
    }
}
