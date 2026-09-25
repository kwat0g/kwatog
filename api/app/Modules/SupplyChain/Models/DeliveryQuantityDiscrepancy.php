<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Enums\DeliveryDiscrepancyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryQuantityDiscrepancy extends Model
{
    use HasHashId, HasAuditLog;

    protected $fillable = ['delivery_id', 'reported_by', 'lines', 'rationale'];

    protected $casts = [
        'lines' => 'array',
        'status' => DeliveryDiscrepancyStatus::class,
        'resolved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalUser::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
