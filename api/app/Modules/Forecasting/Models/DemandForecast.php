<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADV11 — Demand forecast for one (product, customer, month, year).
 *
 * customer_id may be NULL, meaning "total demand across all customers".
 * Method:
 *   - moving_avg     : simple average of last N months of confirmed sales
 *   - weighted_avg   : weighted by recency (most recent month heavier)
 *   - manual         : entered by PPC head, overrides any computed value
 */
class DemandForecast extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    public const METHOD_MOVING_AVG   = 'moving_avg';
    public const METHOD_WEIGHTED_AVG = 'weighted_avg';
    public const METHOD_MANUAL       = 'manual';

    public const METHODS = [
        self::METHOD_MOVING_AVG,
        self::METHOD_WEIGHTED_AVG,
        self::METHOD_MANUAL,
    ];

    protected $fillable = [
        'product_id',
        'customer_id',
        'forecast_month',
        'forecast_year',
        'method',
        'forecasted_quantity',
        'confidence_level',
        'actual_quantity',
        'variance',
        'created_by',
    ];

    protected $casts = [
        'forecast_month'      => 'integer',
        'forecast_year'       => 'integer',
        'forecasted_quantity' => 'decimal:2',
        'confidence_level'    => 'decimal:2',
        'actual_quantity'     => 'decimal:2',
        'variance'            => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
