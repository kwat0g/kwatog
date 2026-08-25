<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Series F — Task F7. Company-wide activity event.
 */
class ActivityEvent extends Model
{
    use HasHashId;

    public $timestamps = false;

    protected $fillable = [
        'type', 'action',
        'actor_user_id', 'actor_type',
        'subject_type', 'subject_id',
        'summary', 'detail', 'link', 'severity',
        'ip_address', 'created_at', 'idempotency_key',
    ];

    protected $casts = [
        'detail'      => 'array',
        'subject_id'  => 'integer',
        'created_at'  => 'datetime',
    ];

    /**
     * Activity events are evidence, not an editable inbox. Producers may
     * append rows (or replay an idempotent write), but no caller may rewrite
     * or remove an event after it has been published.
     */
    public function delete(): ?bool
    {
        throw new \RuntimeException('Activity events are immutable and cannot be deleted.');
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        throw new \RuntimeException('Activity events are immutable and cannot be updated.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException('Activity events are immutable and cannot be updated.');
    }

    public function forceDelete(): bool
    {
        throw new \RuntimeException('Activity events are immutable and cannot be deleted.');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
