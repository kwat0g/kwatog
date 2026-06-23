<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OGAMI-104 — Per-PO-line landed cost allocation for an inbound shipment.
 *
 * One row per purchase_order_item in the shipment, storing that line's share
 * of freight, insurance, duties, brokerage, and other charges.
 */
class ShipmentLandedCost extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'shipment_landed_costs';

    protected $fillable = [
        'shipment_id', 'purchase_order_item_id',
        'allocated_freight', 'allocated_insurance', 'allocated_duties',
        'allocated_brokerage', 'allocated_other', 'total_allocated',
    ];

    protected $casts = [
        'allocated_freight'   => 'decimal:2',
        'allocated_insurance' => 'decimal:2',
        'allocated_duties'    => 'decimal:2',
        'allocated_brokerage' => 'decimal:2',
        'allocated_other'     => 'decimal:2',
        'total_allocated'     => 'decimal:2',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
