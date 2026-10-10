<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Enums\PriceRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceRequest extends Model
{
    use HasAuditLog, HasHashId;

    protected $guarded = ['id'];

    protected $casts = ['status' => PriceRequestStatus::class, 'offer' => 'array', 'history' => 'array', 'requested_price' => 'decimal:2', 'reviewed_at' => 'datetime', 'responded_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
