<?php

declare(strict_types=1);

namespace App\Modules\MRP\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Enums\MrpRunTrigger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Task A1 — One row per execution of MrpEngineService::runForAllActiveSalesOrders().
 */
class MrpRun extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'run_at', 'triggered_by', 'triggered_by_user_id',
        'sales_orders_evaluated', 'shortages_found', 'prs_created',
        'prs_updated', 'plans_generated', 'duration_ms',
        'status', 'error_message', 'summary',
    ];

    protected $casts = [
        'run_at'                 => 'datetime',
        'triggered_by'           => MrpRunTrigger::class,
        'status'                 => MrpRunStatus::class,
        'summary'                => 'array',
        'sales_orders_evaluated' => 'integer',
        'shortages_found'        => 'integer',
        'prs_created'            => 'integer',
        'prs_updated'            => 'integer',
        'plans_generated'        => 'integer',
        'duration_ms'            => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
