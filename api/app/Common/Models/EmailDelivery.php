<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Builder;

class EmailDelivery extends Model
{
    use Prunable;

    protected $fillable = [
        'delivery_key', 'email_type', 'recipients', 'subject', 'status', 'attempts',
        'provider_message_id', 'error_code', 'triggered_by', 'queued_at', 'sent_at', 'failed_at',
    ];

    protected $casts = [
        'recipients' => 'array',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(max(1, (int) config('mail.log_retention_days', 90))));
    }
}
