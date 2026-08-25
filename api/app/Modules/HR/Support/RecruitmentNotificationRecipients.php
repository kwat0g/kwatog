<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Collection;

/** Resolves the configured recipient audience without leaking recruitment PII. */
final class RecruitmentNotificationRecipients
{
    private const VIEW_PERMISSION = 'hr.recruitment.view';

    /** @return Collection<int, User> */
    public static function resolve(SettingsService $settings): Collection
    {
        $roles = array_values(array_filter(
            (array) $settings->get('hr.recruitment.notification_roles', ['hr_officer', 'system_admin']),
            static fn ($role): bool => is_string($role) && $role !== '',
        ));

        // An empty or malformed setting must not silently disable recovery
        // alerts. Resolve configured slugs against live roles first, so stale
        // or misspelled values are filtered instead of becoming an empty
        // audience.
        $roles = Role::query()->whereIn('slug', $roles)->pluck('slug')->all();
        if ($roles === []) {
            $roles = ['hr_officer', 'system_admin'];
        }

        return User::query()
            ->whereHas('role', static fn ($query) => $query->whereIn('slug', $roles))
            ->where('is_active', true)
            ->get()
            // hasPermission includes system_admin and effective user-level
            // grants/revokes, so role membership alone never authorizes a
            // candidate-facing alert.
            ->filter(static fn (User $user): bool => $user->hasPermission(self::VIEW_PERMISSION))
            ->values();
    }
}
