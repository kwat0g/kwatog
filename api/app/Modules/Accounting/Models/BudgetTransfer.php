<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Models\ApprovalDelegation;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Enums\BudgetTransferStatus;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetTransfer extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    protected $fillable = [
        'transfer_number',
        'from_line_item_id',
        'to_line_item_id',
        'month',
        'amount',
        'reason',
        'requested_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function scopeAwaitingApprovalFor(Builder $query, User $approver): Builder
    {
        $permission = 'budgeting.approve';
        $roleIds = ApprovalDelegation::permissionRoleIds($approver, $permission);

        if ($roleIds === []) {
            return $query->whereRaw('1 = 0');
        }
        $onlyApprovalRole = count($roleIds) === 1 ? $roleIds[0] : null;

        return $query->where('status', BudgetTransferStatus::Pending->value)
            ->where(function (Builder $query) use ($approver, $onlyApprovalRole): void {
                $query->whereNull('requested_by')
                    ->orWhereHas('requester', function (Builder $requester) use ($approver, $onlyApprovalRole): void {
                        $requester->whereKeyNot($approver->getKey());
                        if ($onlyApprovalRole !== null) {
                            $requester->where(function (Builder $query) use ($onlyApprovalRole): void {
                                $query->whereNull('role_id')->orWhere('role_id', '<>', $onlyApprovalRole);
                            });
                        }
                    });
            });
    }

    public function fromLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class, 'from_line_item_id');
    }

    public function toLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class, 'to_line_item_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
