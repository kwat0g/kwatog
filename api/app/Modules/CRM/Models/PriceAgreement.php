<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceAgreement extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $table = 'product_price_agreements';

    protected $fillable = [
        'product_id', 'customer_id', 'price',
        'effective_from', 'effective_to',
    ];

    protected $casts = [
        'price'          => 'decimal:2',
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeActiveOn(Builder $q, CarbonInterface $date): Builder
    {
        return $q->whereDate('effective_from', '<=', $date)
                 ->whereDate('effective_to', '>=', $date);
    }

    public function getIsCurrentlyActiveAttribute(): bool
    {
        $today = now()->toDateString();
        return $this->effective_from?->toDateString() <= $today
            && $this->effective_to?->toDateString()   >= $today;
    }
}
