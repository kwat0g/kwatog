<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChainListenerRun extends Model
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_RETRYING = 'retrying';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const OUTCOME_COMPLETED = 'completed';
    public const OUTCOME_SKIPPED = 'skipped';
    public const OUTCOME_MANUAL_REQUIRED = 'manual_required';
    public const OUTCOME_FAILED = 'failed';

    public const RESOLUTION_RESOLVED = 'resolved';

    protected $table = 'chain_listener_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'outbox_id',
        'job_uuid',
        'event_type',
        'listener_class',
        'listener_method',
        'status',
        'attempts',
        'started_at',
        'last_attempt_at',
        'completed_at',
        'failed_at',
        'last_error',
        'outcome_status',
        'outcome_code',
        'outcome_message',
        'outcome_at',
        'resolution_status',
        'resolution_note',
        'resolved_by',
        'resolved_at',
        'replay_count',
        'replay_requested_at',
        'replay_requested_by',
        'replayed_from_id',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'outcome_at' => 'datetime',
        'resolved_by' => 'integer',
        'resolved_at' => 'datetime',
        'replay_count' => 'integer',
        'replay_requested_at' => 'datetime',
        'replay_requested_by' => 'integer',
    ];

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'resolved_by');
    }

    public function replayRequestedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'replay_requested_by');
    }

    public function replayedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replayed_from_id');
    }

    public function replays(): HasMany
    {
        return $this->hasMany(self::class, 'replayed_from_id');
    }
}
