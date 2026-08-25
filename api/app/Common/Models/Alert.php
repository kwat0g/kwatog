<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Task A2 — Alert raised by AlertEngineService. Polymorphic entity reference
 * lets a single row point at any monitored entity (Item, Machine, Mold,
 * WorkOrder, Invoice, Bill, Product).
 */
class Alert extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'type', 'severity', 'title', 'message',
        'entity_type', 'entity_id', 'metadata',
        'condition_key', 'resolved_at',
        'is_read', 'is_dismissed', 'dismissed_by',
        'dismissed_at', 'notified_email_at',
        'email_status', 'email_attempts', 'email_last_attempt_at',
        'email_next_attempt_at', 'email_failed_at', 'email_last_error',
    ];

    protected $casts = [
        'type' => AlertType::class,
        'severity' => AlertSeverity::class,
        'metadata' => 'array',
        'email_attempts' => 'integer',
        'is_read' => 'boolean',
        'is_dismissed' => 'boolean',
        'dismissed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'notified_email_at' => 'datetime',
        'email_last_attempt_at' => 'datetime',
        'email_next_attempt_at' => 'datetime',
        'email_failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $alert): void {
            if ($alert->condition_key === null || $alert->condition_key === '') {
                $alert->condition_key = self::conditionKeyFor(
                    $alert->type,
                    $alert->entity_type,
                    $alert->entity_id,
                );
            }
        });
    }

    /**
     * The condition identity is deliberately independent of the message,
     * metadata, severity, and deduplication window. Those values can change
     * while one monitored condition remains open.
     */
    public static function conditionKeyFor(
        string|BackedEnum $type,
        ?string $entityType,
        int|string|null $entityId,
    ): string {
        $typeValue = $type instanceof BackedEnum ? (string) $type->value : $type;

        return hash('sha256', implode('|', [
            $typeValue,
            $entityType ?? '',
            $entityId === null ? '' : (string) $entityId,
        ]));
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function dismisser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }

    public function scopeActive($query)
    {
        return $query
            ->whereNull('resolved_at')
            ->where('is_dismissed', false);
    }

    public function scopeCurrent($query)
    {
        return $query->whereNull('resolved_at');
    }
}
