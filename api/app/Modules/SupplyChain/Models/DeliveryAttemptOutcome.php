<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\SupplyChain\Enums\DeliveryAttemptReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryAttemptOutcome extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'delivery_id', 'request_key', 'payload_fingerprint', 'reason_code', 'notes', 'reported_by', 'reported_at',
        'received_by', 'receipt_request_key', 'receipt_payload_fingerprint', 'quarantine_location_id',
        'variance_reason', 'reconciled_at', 'return_request_id',
    ];

    protected $casts = [
        'reason_code' => DeliveryAttemptReason::class,
        'reported_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'version' => 'integer',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function quarantineLocation(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Inventory\Models\WarehouseLocation::class, 'quarantine_location_id');
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class, 'delivery_attempt_outcome_id')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DeliveryAttemptRevision::class)->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryAttemptOutcomeItem::class)->orderBy('id');
    }
}
