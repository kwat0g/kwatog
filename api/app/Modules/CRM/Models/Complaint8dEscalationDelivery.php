<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable claim/outcome for one complaint 8D SLA tier.
 *
 * The complaint row remains the lifecycle authority; this row records whether
 * the notification batch for a tier is pending, retryable, or sent.
 */
class Complaint8dEscalationDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'complaint_id',
        'tier',
        'status',
        'attempts',
        'recipient_count',
        'idempotency_key',
        'last_error',
        'last_attempted_at',
        'sent_at',
    ];

    protected $casts = [
        'attempts'          => 'integer',
        'recipient_count'   => 'integer',
        'last_attempted_at' => 'datetime',
        'sent_at'           => 'datetime',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(CustomerComplaint::class, 'complaint_id');
    }
}
