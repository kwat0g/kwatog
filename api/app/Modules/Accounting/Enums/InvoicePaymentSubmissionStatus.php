<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum InvoicePaymentSubmissionStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
