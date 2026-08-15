<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Events;

use App\Modules\Accounting\Models\CreditNote;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CreditNoteFinalized
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly CreditNote $creditNote) {}
}
