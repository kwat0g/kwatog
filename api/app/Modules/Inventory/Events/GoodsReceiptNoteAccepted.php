<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\GoodsReceiptNote;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 2026-08-08 — Fired AFTER GrnService::accept() commits (goods have moved
 * into stock and the inventory JE has posted). Drives the auto-bill chain:
 * the listener pre-creates the supplier bill as a DRAFT so accounting can
 * review and post it.
 */
class GoodsReceiptNoteAccepted
{
    use Dispatchable, SerializesModels;

    public function __construct(public GoodsReceiptNote $grn) {}
}
