<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasHashId;

    public $timestamps = false;
    protected $table = 'audit_logs';
    protected $fillable = [
        'user_id', 'action', 'model_type', 'model_id',
        'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at',
    ];
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Audit logs are append-only. Any attempt to modify or delete one
     * at the application layer throws immediately.
     */
    public function delete(): ?bool
    {
        throw new \RuntimeException('Audit logs are immutable and cannot be deleted.');
    }

    public function save(array $options = []): bool
    {
        // Allow create, block update on existing rows.
        if (! $this->exists) {
            return parent::save($options);
        }
        throw new \RuntimeException('Audit logs are immutable and cannot be updated.');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException('Audit logs are immutable and cannot be updated.');
    }

    public function forceDelete(): bool
    {
        throw new \RuntimeException('Audit logs are immutable and cannot be deleted.');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class);
    }
}
