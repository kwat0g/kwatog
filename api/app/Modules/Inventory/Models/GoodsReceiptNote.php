<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceiptNote extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $table = 'goods_receipt_notes';

    protected $fillable = [
        'grn_number', 'purchase_order_id', 'vendor_id',
        'received_date', 'received_by', 'status',
        'qc_inspection_id', 'accepted_by', 'accepted_at',
        'rejected_reason', 'remarks',
    ];

    protected $casts = [
        'received_date' => 'date',
        'accepted_at'   => 'datetime',
        'status'        => GrnStatus::class,
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
}
