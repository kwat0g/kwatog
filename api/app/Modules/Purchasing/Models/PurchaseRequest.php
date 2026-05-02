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

    protected $fillable = [
        'pr_number', 'requested_by', 'department_id',
        'date', 'reason', 'priority', 'status',
        'is_auto_generated', 'current_approval_step',
        'submitted_at', 'approved_at',
    ];

    protected $casts = [
        'date'                  => 'date',
        'submitted_at'          => 'datetime',
        'approved_at'           => 'datetime',
        'is_auto_generated'     => 'boolean',
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
        $total = 0.0;
        foreach ($this->items as $item) {
            $total += (float) $item->quantity * (float) ($item->estimated_unit_price ?? 0);
        }
        return number_format($total, 2, '.', '');
    }
}
