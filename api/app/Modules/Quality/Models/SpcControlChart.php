<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\SpcChartStatus;
use App\Modules\Quality\Enums\SpcChartType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpcControlChart extends Model
{
    use HasHashId;

    protected $fillable = [
        'product_id', 'spec_item_id', 'chart_type', 'subgroup_size',
        'ucl', 'lcl', 'center_line', 'ucl_range', 'lcl_range', 'center_range',
        'limits_locked', 'limits_sample_count', 'status',
    ];

    protected $casts = [
        'chart_type'          => SpcChartType::class,
        'status'              => SpcChartStatus::class,
        'subgroup_size'       => 'integer',
        'ucl'                 => 'decimal:6',
        'lcl'                 => 'decimal:6',
        'center_line'         => 'decimal:6',
        'ucl_range'           => 'decimal:6',
        'lcl_range'           => 'decimal:6',
        'center_range'        => 'decimal:6',
        'limits_locked'       => 'boolean',
        'limits_sample_count' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function specItem(): BelongsTo
    {
        return $this->belongsTo(InspectionSpecItem::class, 'spec_item_id');
    }

    public function dataPoints(): HasMany
    {
        return $this->hasMany(SpcDataPoint::class, 'control_chart_id')->orderBy('subgroup_number');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(SpcAlert::class, 'control_chart_id');
    }

    public function unresolvedAlerts(): HasMany
    {
        return $this->alerts()->whereNull('resolved_at');
    }
}
