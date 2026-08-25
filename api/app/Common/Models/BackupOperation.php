<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'requested_by',
        'type',
        'status',
        'artifacts',
        'metadata',
        'error_message',
        'active_lock',
        'lease_token',
        'attempts',
        'heartbeat_at',
        'lease_expires_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'artifacts' => 'array',
        'metadata' => 'array',
        'attempts' => 'integer',
        'heartbeat_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
