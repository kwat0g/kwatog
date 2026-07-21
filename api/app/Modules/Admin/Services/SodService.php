<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\SodConflictRule;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Collection;

/**
 * REC-01 — central Segregation-of-Duties engine.
 *
 * Two entry points:
 *   check($user)   — does this user hold BOTH permissions of any active rule?
 *                    Returns the violated rules (empty = clean). Use at
 *                    permission-grant time or as a defensive gate.
 *   scanAllUsers() — the "who violates SoD today" audit report: every active
 *                    user whose effective permissions trip one or more rules.
 *
 * Effective permissions come from User::permission_slugs (role perms + grants
 * − revokes). system_admin resolves the '*' wildcard to every slug, so it will
 * appear to hold every conflicting pair — it is excluded from the report as the
 * intentional break-glass role (documented on the exclusion below).
 */
class SodService
{
    /**
     * @return Collection<int, SodConflictRule> rules this user violates
     */
    public function check(User $user): Collection
    {
        $held = array_flip($user->permission_slugs);

        return $this->activeRules()->filter(function (SodConflictRule $rule) use ($held) {
            return isset($held[$rule->permissionA->slug]) && isset($held[$rule->permissionB->slug]);
        })->values();
    }

    /**
     * @return array<int, array{user: User, rules: Collection<int, SodConflictRule>}>
     */
    public function scanAllUsers(): array
    {
        $rules = $this->activeRules();
        if ($rules->isEmpty()) {
            return [];
        }

        $violations = [];
        User::query()
            ->where('is_active', true)
            // system_admin holds the '*' wildcard by design (break-glass); it would
            // trip every rule and drown the signal, so it is excluded from the report.
            ->whereHas('role', fn ($q) => $q->where('slug', '!=', 'system_admin'))
            ->with('role')
            ->chunkById(200, function ($users) use ($rules, &$violations) {
                foreach ($users as $user) {
                    $held = array_flip($user->permission_slugs);
                    $tripped = $rules->filter(
                        fn (SodConflictRule $r) => isset($held[$r->permissionA->slug]) && isset($held[$r->permissionB->slug])
                    )->values();

                    if ($tripped->isNotEmpty()) {
                        $violations[] = ['user' => $user, 'rules' => $tripped];
                    }
                }
            });

        return $violations;
    }

    /** @return Collection<int, SodConflictRule> */
    private function activeRules(): Collection
    {
        return SodConflictRule::query()
            ->where('active', true)
            ->with(['permissionA:id,slug,name', 'permissionB:id,slug,name'])
            ->get();
    }
}
