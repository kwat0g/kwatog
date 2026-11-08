<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Observers;

use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Services\CreditNoteRealtimeService;

/** Captures credit-note lifecycle writes from API and background code paths. */
final class CreditNoteRealtimeObserver
{
    public function created(CreditNote $creditNote): void
    {
        app(CreditNoteRealtimeService::class)->stage($creditNote, 'created');
    }

    public function updated(CreditNote $creditNote): void
    {
        $action = $creditNote->wasChanged('status') ? 'status_changed' : 'updated';
        app(CreditNoteRealtimeService::class)->stage($creditNote, $action);
    }
}
