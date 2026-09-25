<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Enums\DeliveryCostHandoffStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryCostHandoff extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'delivery_id', 'delivery_attempt_outcome_id', 'request_key', 'payload_fingerprint',
        'handoff_type', 'status', 'target_amount', 'delta_amount', 'source_allocations',
        'message', 'journal_entry_id', 'created_by', 'attempted_at',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'delta_amount' => 'decimal:2',
        'source_allocations' => 'array',
        'status' => DeliveryCostHandoffStatus::class,
        'attempted_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(DeliveryAttemptOutcome::class, 'delivery_attempt_outcome_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
