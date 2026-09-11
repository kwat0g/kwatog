<?php

declare(strict_types=1);

namespace App\Modules\B2B\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Enums\DeliveryScheduleStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliverySchedule extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'customer_id',
        'vendor_id',
        'purchase_order_id',
        'month',
        'status',
        'lines',
        'reviewed_by',
        'reviewed_at',
        'reject_reason',
    ];

    protected $casts = [
        'status' => DeliveryScheduleStatus::class,
        'reviewed_at' => 'datetime',
        'lines' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Restrict to portal-originated rows: customer submissions carry a
     * customer_id, supplier submissions carry a vendor_id. Any other value is a
     * no-op so an unknown source never silently hides rows.
     */
    public function scopeSource(Builder $query, string $source): Builder
    {
        if ($source === 'customer') {
            $query->whereNotNull('customer_id');
        } elseif ($source === 'supplier') {
            $query->whereNotNull('vendor_id');
        }

        return $query;
    }
}
