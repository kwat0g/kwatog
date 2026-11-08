<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Services\GoodsReceiptNoteRealtimeService;

final class GoodsReceiptNoteRealtimeObserver
{
    public function created(GoodsReceiptNote $grn): void
    {
        app(GoodsReceiptNoteRealtimeService::class)->stage($grn, 'created');
    }

    public function updated(GoodsReceiptNote $grn): void
    {
        if ($grn->wasChanged([
            'qc_inspection_id',
            'incoming_qc_handoff_status',
            'incoming_qc_handoff_message',
            'incoming_qc_handoff_at',
            'journal_entry_id',
            'shipment_id',
            'supplier_shipment_id',
        ])) {
            app(GoodsReceiptNoteRealtimeService::class)->stage($grn, 'updated');
        }
    }
}
