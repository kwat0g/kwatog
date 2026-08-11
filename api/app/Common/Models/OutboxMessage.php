<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OutboxMessage extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $table = 'event_outbox';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'event_type',
        'payload',
        'dedupe_key',
        'status',
        'attempts',
        'available_at',
        'locked_at',
        'lease_token',
        'published_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'locked_at' => 'datetime',
        'lease_token' => 'string',
        'published_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function chainStep(): HasOne
    {
        return $this->hasOne(ChainStepRun::class, 'outbox_id');
    }

    public function listenerRuns(): HasMany
    {
        return $this->hasMany(ChainListenerRun::class, 'outbox_id');
    }
}
