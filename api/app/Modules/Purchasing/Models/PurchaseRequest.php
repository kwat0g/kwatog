<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasApprovalWorkflow;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequest extends Model
{
    use HasFactory, HasHashId, HasAuditLog, HasApprovalWorkflow;

    protected static function newFactory(): \Database\Factories\PurchaseRequestFactory
    {
        return \Database\Factories\PurchaseRequestFactory::new();
    }

    protected $fillable = [
        'pr_number', 'requested_by', 'department_id', 'mrp_plan_id',
        'template_id', 'date', 'reason', 'priority',
        'is_auto_generated', 'auto_generated_reason',
        'is_urgent', 'urgency_reason',
    ];

    protected $casts = [
        'date'                  => 'date',
        'submitted_at'          => 'datetime',
        'approved_at'           => 'datetime',
        'is_auto_generated'     => 'boolean',
        'is_urgent'             => 'boolean',
        'current_approval_step' => 'integer',
        'priority'              => PurchaseRequestPriority::class,
        'status'                => PurchaseRequestStatus::class,
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestTemplate::class, 'template_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [
            PurchaseRequestStatus::Draft,
            PurchaseRequestStatus::Pending,
            PurchaseRequestStatus::Approved,
        ]);
    }

    public function totalEstimatedAmount(): string
    {
        $total = (float) $this->items()
            ->selectRaw('COALESCE(SUM(quantity * estimated_unit_price), 0) as total')
            ->value('total');
        return number_format($total, 2, '.', '');
    }
}
