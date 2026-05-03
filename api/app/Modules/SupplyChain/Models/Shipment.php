<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sprint 7 — Task 65. Inbound shipment for an imported PO. */
class Shipment extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'shipment_number', 'purchase_order_id', 'status',
        'carrier', 'vessel', 'container_number', 'bl_number',
        'etd', 'atd', 'eta', 'ata', 'customs_clearance_date',
        'notes', 'created_by',
    ];

    protected $casts = [
        'status'                  => ShipmentStatus::class,
        'etd'                     => 'date',
        'atd'                     => 'date',
        'eta'                     => 'date',
        'ata'                     => 'date',
        'customs_clearance_date'  => 'date',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ShipmentDocument::class)->orderBy('uploaded_at');
    }

    public function scopeStatus(Builder $q, ShipmentStatus|string $s): Builder
    {
        return $q->where('status', $s instanceof ShipmentStatus ? $s->value : $s);
    }
}
