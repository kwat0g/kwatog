<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WS-D.2 — Audit row for one chain transition.
 *
 * Read-only from application code: write only via ChainEventRecorder so
 * the idempotency contract is honoured.
 */
class ChainEvent extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'chain_key',
        'entity_type', 'entity_id',
        'event_type',
        'from_state', 'to_state',
        'actor_id',
        'reason',
        'metadata',
        'idempotency_key',
        'occurred_at',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'occurred_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
