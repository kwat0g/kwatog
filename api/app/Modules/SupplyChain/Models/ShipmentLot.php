<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADV3 — Shipment Lot. One Delivery → one Lot → N production Batches.
 *
 * `work_order_ids` stores the array of WO ids (= batches) bundled into this
 * shipment. Each WO has its own `batch_number` (BATCH-YYYYMM-NNNN); the lot
 * gets its own `lot_number` (LOT-YYYYMM-NNNN) auto-generated via
 * DocumentSequenceService when a delivery is dispatched.
 */
class ShipmentLot extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $table = 'shipment_lots';

    protected $fillable = [
        'lot_number', 'delivery_id', 'customer_id', 'product_id',
        'work_order_ids', 'quantity', 'lot_date', 'coc_path', 'created_by',
    ];

    protected $casts = [
        'work_order_ids' => 'array',
        'lot_date'       => 'date',
        'quantity'       => 'integer',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
