<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\SpcAlertRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpcAlert extends Model
{
    use HasHashId;

    protected $fillable = [
        'control_chart_id', 'data_point_id', 'rule_code', 'severity',
        'acknowledged_by', 'acknowledged_at', 'resolved_at', 'notes',
    ];

    protected $casts = [
        'rule_code'        => SpcAlertRule::class,
        'acknowledged_at'  => 'datetime',
        'resolved_at'      => 'datetime',
    ];

    public function controlChart(): BelongsTo
    {
        return $this->belongsTo(SpcControlChart::class, 'control_chart_id');
    }

    public function dataPoint(): BelongsTo
    {
        return $this->belongsTo(SpcDataPoint::class, 'data_point_id');
    }

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
