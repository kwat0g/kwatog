<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasApprovalWorkflow;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Purchasing\Enums\PurchaseRequestPriority;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class PurchaseRequest extends Model
{
    use HasFactory, HasHashId, HasAuditLog, HasApprovalWorkflow, SoftDeletes;

    protected static function newFactory(): \Database\Factories\PurchaseRequestFactory
    {
        return \Database\Factories\PurchaseRequestFactory::new();
    }

    protected $fillable = [
        'pr_number', 'requested_by', 'department_id', 'mrp_plan_id',
        'template_id', 'date', 'reason', 'priority',
        'is_auto_generated', 'auto_generated_reason',
        'is_urgent', 'urgency_reason',
        'budget_warning_level', 'budget_warning_message',
        'budget_acknowledged_by', 'budget_acknowledged_at',
    ];

    protected $casts = [
        'date'                  => 'date',
        'submitted_at'          => 'datetime',
        'approved_at'           => 'datetime',
        'budget_acknowledged_at' => 'datetime',
        'is_auto_generated'     => 'boolean',
        'is_urgent'             => 'boolean',
        'current_approval_step' => 'integer',
        'priority'              => PurchaseRequestPriority::class,
        'po_conversion_status'  => PurchaseRequestConversionStatus::class,
        'po_conversion_at'      => 'datetime',
        'status'                => PurchaseRequestStatus::class,
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function budgetAcknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'budget_acknowledged_by');
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

    /** The MRP plan that auto-generated this PR (null for manual PRs). */
    public function mrpPlan(): BelongsTo
    {
        return $this->belongsTo(MrpPlan::class);
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

    public function markPoConversionPending(): bool
    {
        return DB::transaction(function (): bool {
            $locked = static::query()->lockForUpdate()->find($this->getKey());
            if (! $locked || $locked->status !== PurchaseRequestStatus::Approved) {
                return false;
            }

            $attributes = [
                'po_conversion_status' => PurchaseRequestConversionStatus::Pending->value,
                'po_conversion_note' => null,
                'po_conversion_at' => now(),
            ];
            $locked->forceFill($attributes)->save();
            $this->forceFill($attributes);

            return true;
        });
    }

    /**
     * Persist the operator handoff without making the queued listener retry a
     * condition that is expected to be fixed manually.
     *
     * @return bool true when the durable outcome changed and a notification
     * should be emitted.
     */
    public function markPoConversionManualRequired(string $note): bool
    {
        return DB::transaction(function () use ($note): bool {
            $locked = static::query()->lockForUpdate()->find($this->getKey());
            if (! $locked || $locked->status !== PurchaseRequestStatus::Approved) {
                return false;
            }

            $changed = $locked->po_conversion_status !== PurchaseRequestConversionStatus::ManualRequired
                || (string) $locked->po_conversion_note !== $note;
            $locked->forceFill([
                'po_conversion_status' => PurchaseRequestConversionStatus::ManualRequired->value,
                'po_conversion_note' => $note,
                'po_conversion_at' => $changed ? now() : $locked->po_conversion_at,
            ])->save();

            return $changed;
        });
    }

    public function markPoConversionConverted(): void
    {
        $this->forceFill([
            'po_conversion_status' => PurchaseRequestConversionStatus::Converted,
            'po_conversion_note' => null,
            'po_conversion_at' => now(),
        ])->save();
    }
}
