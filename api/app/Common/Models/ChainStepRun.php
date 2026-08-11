<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChainStepRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    protected $table = 'chain_step_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'outbox_id',
        'chain',
        'entity_type',
        'entity_id',
        'entity_hash_id',
        'step',
        'event_type',
        'event_key',
        'status',
        'attempts',
        'last_attempt_at',
        'completed_at',
        'last_error',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function outbox(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_id');
    }
}
