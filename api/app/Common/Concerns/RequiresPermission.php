<?php

declare(strict_types=1);

namespace App\Common\Concerns;

/**
 * Mixin for FormRequests that enforces a specific permission in
 * authorize() instead of returning (bool) $this->user().
 *
 * Usage:
 *     public function requiredPermission(): ?string { return 'leave.create'; }
 */
trait RequiresPermission
{
    abstract protected function requiredPermission(): ?string;

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }
        $perm = $this->requiredPermission();
        return $perm === null || $user->hasPermission($perm);
    }
}