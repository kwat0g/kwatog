<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Series F — Task F7. Company-wide activity event.
 */
class ActivityEvent extends Model
{
    use HasHashId;

    public $timestamps = false;

    protected $fillable = [
        'type', 'action',
        'actor_user_id', 'actor_type',
        'subject_type', 'subject_id',
        'summary', 'detail', 'link', 'severity',
        'ip_address', 'created_at',
    ];

    protected $casts = [
        'detail'      => 'array',
        'subject_id'  => 'integer',
        'created_at'  => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
