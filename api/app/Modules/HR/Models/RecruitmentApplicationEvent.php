<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable, redacted workflow evidence for a recruitment application.
 *
 * Candidate contact details, resume contents, and note bodies are deliberately
 * not stored here. The event is a decision trail, not a second PII archive.
 */
class RecruitmentApplicationEvent extends Model
{
    use HasHashId;

    public $timestamps = false;

    protected $fillable = [
        'job_application_id',
        'actor_user_id',
        'actor_type',
        'event_type',
        'from_stage',
        'to_stage',
        'before_values',
        'after_values',
        'metadata',
        'correlation_id',
        'created_at',
    ];

    protected $casts = [
        'before_values' => 'array',
        'after_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'job_application_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \RuntimeException('Recruitment application events are immutable and cannot be updated.');
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException('Recruitment application events are immutable and cannot be updated.');
    }

    public function delete(): ?bool
    {
        throw new \RuntimeException('Recruitment application events are immutable and cannot be deleted.');
    }

    public function forceDelete(): bool
    {
        throw new \RuntimeException('Recruitment application events are immutable and cannot be deleted.');
    }
}
