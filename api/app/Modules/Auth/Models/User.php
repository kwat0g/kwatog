<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Common\Support\FailOpenCache;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Services\RolePermissionCacheGeneration;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, HasHashId;

    /**
     * Internal login identity is case-insensitive. Keep every model-backed
     * write canonical so the database's existing unique email constraint also
     * behaves case-insensitively for application writes.
     */
    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

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
        'sidebar_peek',
        'sidebar_expand_all',
        'table_preferences',
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
            'sidebar_peek'          => 'boolean',
            'sidebar_expand_all'    => 'boolean',
            'table_preferences'     => 'array',
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
     * Series R — Task R2; H-9 freshness fix.
     *
     * Effective permission set = role permissions + grants - revokes.
     *
     * Caching strategy: the ROLE permission set is cached by restore namespace,
     * role_id, and permissions_version (300s) because role-permission sync is
     * rare. Overrides carry expires_at and
     * therefore CANNOT be cached at the resolved-slug level — a Grant cached
     * at t=0 with expiry t=60s would otherwise keep acting granted for the
     * full 300s TTL. Overrides are read fresh per call so expiry is honored
     * within seconds, not minutes.
     *
     * The system_admin short-circuit lives in hasPermission() — overrides
     * are intentionally NOT applied to system_admin so the role remains a
     * hard escape hatch (deliberate policy; documented on the PR).
     */
    /** Request-scoped memo of resolved slugs. Avoids re-querying overrides on
     *  every hasPermission() call within one request. Cleared by flushPermissionsCache. */
    private ?array $resolvedPermissionSlugs = null;

    public function getPermissionSlugsAttribute(): array
    {
        if ($this->resolvedPermissionSlugs !== null) {
            return $this->resolvedPermissionSlugs;
        }

        if (! $this->role) {
            return $this->resolvedPermissionSlugs = [];
        }

        $role = $this->role;
        $permissionsVersion = $role->getAttribute('permissions_version');
        if ($permissionsVersion === null) {
            // Some read paths eager-load only role identity fields. Resolve the
            // version explicitly so those partial relations cannot fall back
            // to a stale, unversioned permission snapshot.
            $permissionsVersion = DB::table('roles')->where('id', $this->role_id)->value('permissions_version');
        }
        $permissionsVersion = (int) ($permissionsVersion ?? 1);

        /** @var array<int, string> $rolePerms */
        $rolePerms = FailOpenCache::remember(
            Cache::store(),
            app(RolePermissionCacheGeneration::class)->keyFor((int) $this->role_id, $permissionsVersion),
            300,
            fn () => $role->permissions()->pluck('permissions.slug', 'permissions.id')->all(),
            'auth.role_permissions',
        );

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

        return $this->resolvedPermissionSlugs = array_values($rolePerms);
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
        $this->resolvedPermissionSlugs = null;
        $userId = $this->id;
        $roleId = $this->role_id;

        DB::afterCommit(static function () use ($userId, $roleId): void {
            try {
                // Legacy keys — new role permission snapshots use the database
                // version in their key, so a late pre-commit fill is unreachable.
                Cache::forget("auth:permissions:{$userId}");
                if ($roleId) {
                    Cache::forget("auth:role_perms:{$roleId}");
                }
            } catch (Throwable $exception) {
                // Do not let cache cleanup suppress later access-refresh signals
                // for this committed user/role mutation.
                Log::warning('Permission cache cleanup failed after commit.', [
                    'user_scope_hash' => hash('sha256', (string) $userId),
                    'exception' => $exception::class,
                ]);
            }
        });
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
