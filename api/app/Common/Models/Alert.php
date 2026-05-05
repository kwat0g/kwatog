<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
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
        'is_read', 'is_dismissed', 'dismissed_by',
        'dismissed_at', 'notified_email_at',
    ];

    protected $casts = [
        'type'              => AlertType::class,
        'severity'          => AlertSeverity::class,
        'metadata'          => 'array',
        'is_read'           => 'boolean',
        'is_dismissed'      => 'boolean',
        'dismissed_at'      => 'datetime',
        'notified_email_at' => 'datetime',
    ];

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
        return $query->where('is_dismissed', false);
    }
}
