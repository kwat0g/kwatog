<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Series F — Task F4. Persisted monthly supplier performance metrics.
 */
class SupplierPerformanceSnapshot extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'vendor_id',
        'period_year', 'period_month',
        'on_time_delivery_rate',
        'quality_pass_rate',
        'incoming_quality_rate',
        'in_process_quality_rate',
        'outgoing_quality_rate',
        'ncr_rate',
        'price_variance_pct',
        'lead_time_variance_days',
        'overall_score',
        'po_count', 'grn_count',
        'computed_at',
    ];

    protected $casts = [
        'period_year'              => 'integer',
        'period_month'             => 'integer',
        'on_time_delivery_rate'    => 'decimal:2',
        'quality_pass_rate'        => 'decimal:2',
        'incoming_quality_rate'    => 'decimal:2',
        'in_process_quality_rate'  => 'decimal:2',
        'outgoing_quality_rate'    => 'decimal:2',
        'ncr_rate'                 => 'decimal:2',
        'price_variance_pct'       => 'decimal:2',
        'lead_time_variance_days'  => 'decimal:2',
        'overall_score'            => 'decimal:2',
        'po_count'                 => 'integer',
        'grn_count'                => 'integer',
        'computed_at'              => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
