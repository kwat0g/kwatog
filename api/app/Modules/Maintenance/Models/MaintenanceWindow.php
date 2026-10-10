<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class MaintenanceWindow extends Model
{
    use HasHashId;

    protected $fillable = ['maintainable_type', 'maintainable_id', 'work_order_id', 'planned_start_at', 'planned_end_at', 'state', 'confirmed_by', 'confirmed_at'];

    protected $casts = ['planned_start_at' => 'datetime', 'planned_end_at' => 'datetime', 'confirmed_at' => 'datetime'];

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('state', 'confirmed');
    }

    public function scopeOverlapping(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->where('planned_start_at', '<', $end)->where('planned_end_at', '>', $start);
    }
}
