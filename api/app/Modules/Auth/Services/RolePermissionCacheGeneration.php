<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Support\CacheGeneration;
use Illuminate\Support\Str;

/** Namespaces role permission snapshots across database restore boundaries. */
final class RolePermissionCacheGeneration
{
    private const KEY = 'auth:role_perms:generation';

    private const TTL_SECONDS = 604800;

    private ?string $generation = null;

    public function keyFor(int $roleId, int $permissionsVersion): string
    {
        return sprintf('auth:role_perms:g%s:r%d:v%d', $this->current(), $roleId, $permissionsVersion);
    }

    public function current(): string
    {
        if ($this->generation !== null) {
            return $this->generation;
        }

        return $this->generation = CacheGeneration::currentDefault(
            self::KEY,
            self::TTL_SECONDS,
            'auth.role_permissions',
        );
    }

    public function invalidateForDatabaseRestore(): bool
    {
        return $this->rotate('database restore');
    }

    public function invalidateAfterMigrations(): bool
    {
        return $this->rotate('migration completion');
    }

    private function rotate(string $reason): bool
    {
        $generation = (string) Str::uuid();
        $rotated = CacheGeneration::rotateDefault(
            self::KEY,
            self::TTL_SECONDS,
            'auth.role_permissions.'.$reason,
            $generation,
        );
        if ($rotated) {
            $this->generation = $generation;
        }

        return $rotated;
    }
}
