<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, HasHashId;

    /**
     * Always eager-load `role` so resources / authorization checks that call
     * `$user->role` (e.g. EmployeeResource::maskField, hasPermission) don't
     * trip Model::shouldBeStrict()'s lazy-loading guard outside production.
     */
    protected $with = ['role'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'employee_id',
        'is_active',
        'must_change_password',
        'last_activity',
        'password_changed_at',
        'failed_login_attempts',
        'locked_until',
        'theme_mode',
        'sidebar_collapsed',
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
            'is_active'             => 'boolean',
            'must_change_password'  => 'boolean',
            'sidebar_collapsed'     => 'boolean',
            'last_activity'         => 'datetime',
            'password_changed_at'   => 'datetime',
            'locked_until'          => 'datetime',
            'password'              => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function passwordHistory(): HasMany
    {
        return $this->hasMany(PasswordHistory::class)->orderByDesc('created_at');
    }

    /**
     * @return array<int, string>
     */
    public function getPermissionSlugsAttribute(): array
    {
        return Cache::remember(
            "auth:permissions:{$this->id}",
            300,
            fn () => $this->role
                ? $this->role->permissions()->pluck('slug')->all()
                : []
        );
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->role?->slug === 'system_admin') {
            return true;
        }
        return in_array($slug, $this->permission_slugs, true);
    }

    public function flushPermissionsCache(): void
    {
        Cache::forget("auth:permissions:{$this->id}");
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
