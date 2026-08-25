<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NcrEscalationDelivery extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'ncr_id', 'tier', 'role', 'subject', 'status', 'attempts', 'recipient_count',
        'idempotency_key', 'last_error', 'last_attempted_at', 'sent_at',
    ];

    protected $casts = [
        'tier'              => 'integer',
        'attempts'          => 'integer',
        'recipient_count'   => 'integer',
        'last_attempted_at' => 'datetime',
        'sent_at'           => 'datetime',
    ];

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class, 'ncr_id');
    }
}
