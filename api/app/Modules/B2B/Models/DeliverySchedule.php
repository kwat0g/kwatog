<?php

declare(strict_types=1);

namespace App\Modules\B2B\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Models\PurchaseOrder;
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
    ];

    protected $casts = [
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
}
