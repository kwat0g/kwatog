<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Modules\Auth\Models\User;

/**
 * Resolves the configured automation actor for system-initiated records
 * (auto-PRs from low-stock replenishment, auto-POs from approved PRs, …).
 *
 * Reads `system.automation.actor_roles` — the set of role slugs permitted to
 * stand in for a human actor. Returns null when no role or no eligible user
 * exists, so automated records are never attributed randomly.
 */
class SystemActorService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function resolve(): ?User
    {
        $roles = array_values(array_filter(
            (array) $this->settings->get('system.automation.actor_roles', []),
            static fn ($role): bool => is_string($role) && $role !== '',
        ));
        if ($roles === []) {
            return null;
        }

        return User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
            ->orderBy('id')
            ->first();
    }
}
