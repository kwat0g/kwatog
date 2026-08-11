<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\IncomingQcHandoffStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceiptNote extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected static function newFactory(): \Database\Factories\GoodsReceiptNoteFactory
    {
        return \Database\Factories\GoodsReceiptNoteFactory::new();
    }

    protected $table = 'goods_receipt_notes';

    protected $fillable = [
        'grn_number', 'purchase_order_id', 'vendor_id',
        'received_date', 'received_by', 'status',
        'qc_inspection_id', 'accepted_by', 'accepted_at',
        'incoming_qc_handoff_status', 'incoming_qc_handoff_message', 'incoming_qc_handoff_at',
        'rejected_reason', 'remarks', 'journal_entry_id',
    ];

    protected $casts = [
        'received_date' => 'date',
        'accepted_at'   => 'datetime',
        'status'        => GrnStatus::class,
        'incoming_qc_handoff_status' => IncomingQcHandoffStatus::class,
        'incoming_qc_handoff_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GrnItem::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /** 2026-08-08 — bills auto-created from this receipt (one per accepted GRN). */
    public function bills(): HasMany
    {
        return $this->hasMany(\App\Modules\Accounting\Models\Bill::class);
    }
}
