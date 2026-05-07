<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\GoodsReceiptNote;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C2. Fired AFTER GrnService::create() commits. Drives
 * the TriggerIncomingQC listener which creates a pending inspection on
 * the incoming materials.
 */
class GoodsReceiptNoteCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public GoodsReceiptNote $grn) {}
}
