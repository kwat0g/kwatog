<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Models\Bom;
use App\Modules\Quality\Models\InspectionSpec;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasAuditLog, HasFactory, HasHashId, SoftDeletes;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected $fillable = [
        'part_number', 'name', 'description', 'unit_of_measure',
        'standard_cost', 'revenue_account_id', 'is_active',
        'include_forecast_in_mrp', 'base_selling_price', 'minimum_selling_price', 'portal_visibility',
        'image_path',
    ];

    protected $casts = [
        'financial_change_request' => 'array',
        'standard_cost' => 'decimal:2',
        'base_selling_price' => 'decimal:2',
        'minimum_selling_price' => 'decimal:2',
        'is_active' => 'boolean',
        'include_forecast_in_mrp' => 'boolean',
    ];

    public function portalCustomers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_product_access');
    }

    public function isAvailableToCustomer(int $customerId): bool
    {
        return $this->is_active && ! $this->trashed()
            && ($this->portal_visibility === 'standard' || $this->portalCustomers()->whereKey($customerId)->exists());
    }

    public function priceAgreements(): HasMany
    {
        return $this->hasMany(PriceAgreement::class);
    }

    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function inventoryItem(): HasOne
    {
        return $this->hasOne(Item::class, 'code', 'part_number')->withTrashed();
    }

    public function getFinishedGoodReadinessAttribute(): array
    {
        $item = $this->relationLoaded('inventoryItem') ? $this->inventoryItem : null;
        $reason = $item === null ? 'No matching finished-goods inventory item.'
            : ($item->trashed() || ! $item->is_active ? 'Matching inventory item is inactive or archived.'
            : ($item->item_type->value !== 'finished_good' ? 'Matching inventory item must be a finished good.'
            : (strcasecmp($item->unit_of_measure, $this->unit_of_measure) !== 0 ? 'Product and inventory base units do not match.' : null)));

        return ['ready' => $reason === null, 'reason' => $reason, 'item_id' => $item?->hash_id, 'item_code' => $item?->code];
    }

    public function activeBom(): HasOne
    {
        return $this->hasOne(Bom::class)->where('is_active', true);
    }

    public function inspectionSpec(): HasOne
    {
        return $this->hasOne(InspectionSpec::class)->where('is_active', true);
    }

    /**
     * C-1 — optional override for the GL revenue account used by the delivery
     * auto-invoice flow. Falls back to the setting
     * `accounting.default_sales_revenue_account_code` when null.
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
