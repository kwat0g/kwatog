<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of delivery date moves. One row per reschedule captures
 * the from/to dates, the operator's reason, and who made the change.
 */
class DeliveryReschedule extends Model
{
    use HasHashId;

    protected $fillable = [
        'delivery_id',
        'from_date',
        'to_date',
        'reason',
        'rescheduled_by',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function rescheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rescheduled_by');
    }
}
