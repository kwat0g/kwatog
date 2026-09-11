<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderResponse extends Model
{
    use HasAuditLog, HasHashId;

    protected $fillable = [
        'purchase_order_id', 'vendor_id', 'portal_user_id',
        'response_type', 'status', 'proposed_delivery_date',
        'notes', 'responded_at',
        'resolved_by', 'resolved_at', 'resolution_notes',
    ];

    protected $casts = [
        'proposed_delivery_date' => 'date',
        'responded_at'           => 'datetime',
        'resolved_at'            => 'datetime',
        'response_type'          => PurchaseOrderResponseType::class,
        'status'                 => PurchaseOrderResponseStatus::class,
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(SupplierPortalUser::class, 'portal_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderResponseItem::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
