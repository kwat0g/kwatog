<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 7 — Task 61. Non-Conformance Report root row.
 */
class NonConformanceReport extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $table = 'non_conformance_reports';

    protected $fillable = [
        'ncr_number', 'source', 'severity', 'status',
        'product_id', 'inspection_id', 'complaint_id',
        'defect_description', 'affected_quantity', 'disposition',
        'root_cause', 'corrective_action',
        'created_by', 'assigned_to', 'closed_by', 'closed_at',
        'replacement_work_order_id',
    ];

    protected $casts = [
        'source'            => NcrSource::class,
        'severity'          => NcrSeverity::class,
        'status'            => NcrStatus::class,
        'disposition'       => NcrDisposition::class,
        'affected_quantity' => 'integer',
        'closed_at'         => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(NcrAction::class, 'ncr_id')->orderBy('performed_at');
    }

    public function replacementWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'replacement_work_order_id');
    }

    public function scopeStatus(Builder $q, NcrStatus|string $status): Builder
    {
        return $q->where('status', $status instanceof NcrStatus ? $status->value : $status);
    }
}
