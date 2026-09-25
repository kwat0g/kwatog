<?php

declare(strict_types=1);

namespace App\Modules\MRP\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class MrpPlan extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'mrp_plan_no', 'sales_order_id', 'version', 'status',
        'generated_by', 'mrp_run_id', 'total_lines', 'shortages_found',
        'auto_pr_count', 'draft_wo_count', 'diagnostics', 'cost_summary',
        'generation_context', 'generated_at',
    ];

    protected $casts = [
        'status'          => MrpPlanStatus::class,
        'version'         => 'integer',
        'total_lines'     => 'integer',
        'shortages_found' => 'integer',
        'auto_pr_count'   => 'integer',
        'draft_wo_count'  => 'integer',
        'diagnostics'     => 'array',
        'cost_summary'    => 'array',
        'generation_context' => 'array',
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

    public function mrpRun(): BelongsTo
    {
        return $this->belongsTo(MrpRun::class, 'mrp_run_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /** Earlier WOs still owning or having fulfilled demand for this sales order. */
    public function priorProgressedWorkOrders(): HasManyThrough
    {
        return $this->hasManyThrough(WorkOrder::class, self::class, 'sales_order_id', 'mrp_plan_id', 'sales_order_id', 'id')
            ->where(fn ($q) => $q->whereNotIn('work_orders.status', [WorkOrderStatus::Planned->value, WorkOrderStatus::Cancelled->value])
                ->orWhere(fn ($cancelled) => $cancelled->where('work_orders.status', WorkOrderStatus::Cancelled->value)
                    ->where('work_orders.quantity_good', '>', 0)));
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    /** Progressed auto-PRs linked to this SO; detail limits these to earlier versions. */
    public function priorProgressedPurchaseRequests(): HasManyThrough
    {
        return $this->hasManyThrough(PurchaseRequest::class, self::class, 'sales_order_id', 'mrp_plan_id', 'sales_order_id', 'id')
            ->where('purchase_requests.is_auto_generated', true)
            ->whereIn('purchase_requests.status', [
                PurchaseRequestStatus::Pending->value,
                PurchaseRequestStatus::Approved->value,
                PurchaseRequestStatus::Converted->value,
            ]);
    }
}
