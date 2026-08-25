<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NcrEffectivenessNotification extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'ncr_action_id', 'user_id', 'notification_type', 'due_date', 'idempotency_key', 'sent_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'sent_at'  => 'datetime',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(NcrAction::class, 'ncr_action_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
