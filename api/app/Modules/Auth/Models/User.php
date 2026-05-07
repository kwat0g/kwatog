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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Models\Employee::class, 'employee_id');
    }

    public function passwordHistory(): HasMany
    {
        return $this->hasMany(PasswordHistory::class)->orderByDesc('created_at');
    }

    public function loginHistory(): HasMany
    {
        return $this->hasMany(\App\Modules\Admin\Models\LoginHistory::class)->orderByDesc('created_at');
    }

    /**
     * @return array<int, string>
     *
     * Series R — Task R2.
     *
     * Effective permission set = role permissions + grants - revokes,
     * with expired overrides ignored. Cached for 5 min; mutations through
     * UserPermissionOverrideService::set / ::remove flush this key via
     * flushPermissionsCache(). RolePermissionSync flushes all caches.
     *
     * The system_admin short-circuit lives in hasPermission() — overrides
     * are intentionally NOT applied to system_admin so the role remains a
     * hard escape hatch (deliberate policy; documented on the PR).
     */
    public function getPermissionSlugsAttribute(): array
    {
        return Cache::remember(
            "auth:permissions:{$this->id}",
            300,
            function () {
                if (! $this->role) {
                    return [];
                }

                /** @var array<int, string> $rolePerms */
                $rolePerms = $this->role->permissions()->pluck('permissions.slug', 'permissions.id')->all();

                $overrideRows = \App\Modules\Admin\Models\UserPermissionOverride::query()
                    ->where('user_id', $this->id)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->with('permission:id,slug')
                    ->get();

                foreach ($overrideRows as $row) {
                    /** @var \App\Modules\Auth\Models\Permission|null $perm */
                    $perm = $row->permission;
                    if (! $perm) {
                        continue;
                    }

                    $type = $row->type instanceof \App\Common\Enums\PermissionOverrideType
                        ? $row->type
                        : \App\Common\Enums\PermissionOverrideType::tryFrom((string) $row->type);

                    if ($type === \App\Common\Enums\PermissionOverrideType::Grant) {
                        $rolePerms[$perm->id] = $perm->slug;
                    } elseif ($type === \App\Common\Enums\PermissionOverrideType::Revoke) {
                        unset($rolePerms[$perm->id]);
                    }
                }

                return array_values($rolePerms);
            },
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
