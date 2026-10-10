<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderCrew extends Model
{
    use HasHashId;

    public $timestamps = false;

    protected $fillable = ['work_order_id', 'rework_ncr_id', 'recorder_employee_id', 'assigned_by', 'reason', 'assigned_at', 'ended_at'];

    protected $casts = ['assigned_at' => 'datetime', 'ended_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(WorkOrderCrewMember::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class, 'rework_ncr_id');
    }
}
