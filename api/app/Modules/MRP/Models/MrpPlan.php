<?php

declare(strict_types=1);

namespace App\Modules\MRP\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MrpPlan extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'mrp_plan_no', 'sales_order_id', 'version', 'status',
        'generated_by', 'total_lines', 'shortages_found',
        'auto_pr_count', 'draft_wo_count', 'diagnostics', 'generated_at',
    ];

    protected $casts = [
        'status'          => MrpPlanStatus::class,
        'version'         => 'integer',
        'total_lines'     => 'integer',
        'shortages_found' => 'integer',
        'auto_pr_count'   => 'integer',
        'draft_wo_count'  => 'integer',
        'diagnostics'     => 'array',
        'generated_at'    => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }
}
