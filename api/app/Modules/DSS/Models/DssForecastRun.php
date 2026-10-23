<?php

declare(strict_types=1);

namespace App\Modules\DSS\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Accuracy record for a detector's forecasting method (Wave 3 consumes these). */
class DssForecastRun extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'dss_forecast_runs';

    protected $fillable = [
        'detector_id',
        'method',
        'window_start',
        'window_end',
        'mape',
        'mae',
        'bias',
        'series_count',
        'data_points',
        'sufficient',
        'metadata',
    ];

    protected $casts = [
        'window_start' => 'date',
        'window_end' => 'date',
        'mape' => 'decimal:4',
        'mae' => 'decimal:4',
        'bias' => 'decimal:4',
        'series_count' => 'integer',
        'data_points' => 'integer',
        'sufficient' => 'boolean',
        'metadata' => 'array',
    ];
}
