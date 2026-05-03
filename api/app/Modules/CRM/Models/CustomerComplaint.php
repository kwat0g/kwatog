<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Sprint 7 — Task 68. */
class CustomerComplaint extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'complaint_number', 'customer_id', 'product_id', 'sales_order_id',
        'received_date', 'severity', 'status', 'description',
        'affected_quantity', 'ncr_id', 'replacement_work_order_id',
        'credit_memo_id', 'created_by', 'assigned_to',
        'resolved_at', 'closed_at',
    ];

    protected $casts = [
        'severity'          => NcrSeverity::class,    // shared scale with NCR
        'status'            => ComplaintStatus::class,
        'received_date'     => 'date',
        'affected_quantity' => 'integer',
        'resolved_at'       => 'datetime',
        'closed_at'         => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class, 'ncr_id');
    }

    public function replacementWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'replacement_work_order_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function eightDReport(): HasOne
    {
        return $this->hasOne(Complaint8DReport::class, 'complaint_id');
    }

    public function scopeStatus(Builder $q, string $status): Builder
    {
        return $q->where('status', $status);
    }
}
