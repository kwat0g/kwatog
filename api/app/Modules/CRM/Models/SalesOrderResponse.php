<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\SalesOrderResponseStatus;
use App\Modules\CRM\Enums\SalesOrderResponseType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Customer negotiation reply against a sales order. See the migration for the
 * lifecycle rule; the shape mirrors PurchaseOrderResponse on the supplier side.
 */
class SalesOrderResponse extends Model
{
    use HasAuditLog, HasHashId;

    protected $fillable = [
        'sales_order_id', 'customer_id', 'portal_user_id',
        'response_type', 'status', 'proposed_delivery_date',
        'notes', 'responded_at',
        'resolved_by', 'resolved_at', 'resolution_notes',
    ];

    protected $casts = [
        'proposed_delivery_date' => 'date',
        'responded_at'           => 'datetime',
        'resolved_at'            => 'datetime',
        'response_type'          => SalesOrderResponseType::class,
        'status'                 => SalesOrderResponseStatus::class,
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalUser::class, 'portal_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderResponseItem::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
