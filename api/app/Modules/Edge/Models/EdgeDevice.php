<?php

declare(strict_types=1);

namespace App\Modules\Edge\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Edge\Enums\EdgeDeviceType;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * T2.0 — Factory-floor device (PLC, scanner, IoT sensor, caliper, scale).
 *
 * Authenticates against the `edge_device` guard with a Sanctum bearer
 * token. Abilities are pinned per device_type at issue time so a scanner
 * cannot post PLC counts.
 */
class EdgeDevice extends Model implements AuthenticatableContract, HasApiTokensContract
{
    use HasFactory, HasHashId, HasAuditLog, HasApiTokens, Authorizable;

    protected $fillable = [
        'serial_number', 'name', 'device_type', 'location', 'machine_id', 'notes',
    ];

    protected $casts = [
        'device_type'  => EdgeDeviceType::class,
        'is_active'    => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Auth contract — devices have no password/email/remember_token. We
     * implement the minimal surface needed so Sanctum's bearer-token
     * resolver treats us like a user.
     */
    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): mixed { return $this->id; }
    public function getAuthPassword(): string { return ''; }
    public function getAuthPasswordName(): string { return 'password'; }
    public function getRememberToken(): string { return ''; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return ''; }

    public function machine(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\MRP\Models\Machine::class);
    }
}
