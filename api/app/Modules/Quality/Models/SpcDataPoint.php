<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpcDataPoint extends Model
{
    use HasHashId;

    protected $fillable = [
        'control_chart_id', 'subgroup_number', 'subgroup_mean', 'subgroup_range',
        'subgroup_std_dev', 'individual_value', 'moving_range',
        'sample_values', 'recorded_at', 'alerts', 'inspection_ids',
    ];

    protected $casts = [
        'subgroup_number'  => 'integer',
        'subgroup_mean'    => 'decimal:6',
        'subgroup_range'   => 'decimal:6',
        'subgroup_std_dev' => 'decimal:6',
        'individual_value' => 'decimal:6',
        'moving_range'     => 'decimal:6',
        'sample_values'    => 'array',
        'alerts'           => 'array',
        'inspection_ids'   => 'array',
        'recorded_at'      => 'datetime',
    ];

    public function controlChart(): BelongsTo
    {
        return $this->belongsTo(SpcControlChart::class, 'control_chart_id');
    }
}
