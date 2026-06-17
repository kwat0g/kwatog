<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, time-boxed self-service password reset token.
 *
 * The raw token is only ever known to the recipient (emailed in the reset
 * link); we persist its sha256 hash. Lookups hash the incoming token and match
 * against `token_hash`, then assert `used_at IS NULL` and `expires_at` future.
 */
class PasswordResetRequest extends Model
{
    use HasHashId;

    // `used_at` is intentionally NOT fillable — it is only ever set by the
    // service via forceFill() when a token is redeemed.
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at'    => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
