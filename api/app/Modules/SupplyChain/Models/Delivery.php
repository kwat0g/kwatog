<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'delivery_number', 'sales_order_id', 'vehicle_id', 'driver_id',
        'status', 'scheduled_date', 'departed_at', 'delivered_at',
        'confirmed_at', 'confirmed_by', 'receipt_photo_path',
        'invoice_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'status'         => DeliveryStatus::class,
        'scheduled_date' => 'date',
        'departed_at'    => 'datetime',
        'delivered_at'   => 'datetime',
        'confirmed_at'   => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function scopeStatus(Builder $q, DeliveryStatus|string $s): Builder
    {
        return $q->where('status', $s instanceof DeliveryStatus ? $s->value : $s);
    }
}
