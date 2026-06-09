<?php

declare(strict_types=1);

namespace App\Modules\B2B\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class CustomerPortalUser extends Authenticatable
{
    use HasApiTokens, HasFactory, HasHashId, Notifiable, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
        'failed_login_attempts',
        'locked_until',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'failed_login_attempts',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active'           => 'boolean',
            'last_login_at'       => 'datetime',
            'locked_until'        => 'datetime',
            'password_changed_at' => 'datetime',
            'password'            => 'hashed',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
