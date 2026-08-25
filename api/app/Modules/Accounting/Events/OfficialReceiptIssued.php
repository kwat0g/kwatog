<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Events;

use App\Modules\Accounting\Models\OfficialReceipt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class OfficialReceiptIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly OfficialReceipt $officialReceipt) {}
}
