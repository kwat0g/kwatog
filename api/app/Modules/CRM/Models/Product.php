<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

    protected static function newFactory(): \Database\Factories\ProductFactory
    {
        return \Database\Factories\ProductFactory::new();
    }

    protected $fillable = [
        'part_number', 'name', 'description', 'unit_of_measure',
        'standard_cost', 'revenue_account_id', 'is_active',
    ];

    protected $casts = [
        'standard_cost' => 'decimal:2',
        'is_active'     => 'boolean',
    ];

    public function priceAgreements(): HasMany
    {
        return $this->hasMany(PriceAgreement::class);
    }

    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    /**
     * C-1 — optional override for the GL revenue account used by the delivery
     * auto-invoice flow. Falls back to the setting
     * `accounting.default_sales_revenue_account_code` (default '4010') when null.
     */
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Whether the product currently has an active BOM (Task 49). The BOM model
     * is in App\Modules\MRP\Models\Bom but isn't required for this query —
     * answered via a `whereExists`-style subquery from the service.
     */
    public function getHasBomAttribute(): bool
    {
        if (array_key_exists('has_bom_flag', $this->attributes)) {
            return (bool) $this->attributes['has_bom_flag'];
        }
        return false;
    }
}
