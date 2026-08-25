<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NcrRecurrenceScan extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'ncr_id', 'status', 'attempts', 'available_at', 'started_at', 'completed_at',
        'notification_sent_at', 'last_error',
    ];

    protected $casts = [
        'attempts'      => 'integer',
        'available_at'  => 'datetime',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
        'notification_sent_at' => 'datetime',
    ];

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class, 'ncr_id');
    }
}
