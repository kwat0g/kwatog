<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Assets\Enums\TransferStatus;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetTransfer extends Model
{
    use HasHashId, HasAuditLog;

    protected $fillable = [
        'transfer_number',
        'asset_id',
        'from_department_id',
        'to_department_id',
        'reason',
        'transfer_date',
        'requested_by',
    ];

    protected $casts = [
        'status'        => TransferStatus::class,
        'transfer_date' => 'date',
        'approved_at'   => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
