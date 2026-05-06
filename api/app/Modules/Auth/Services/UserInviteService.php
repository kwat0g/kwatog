<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\UserInvite;
use App\Modules\HR\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * WS-A.1 — Issue, list, accept, and revoke portal-account invites.
 *
 * The service is the single place where invites are mutated; controllers
 * delegate every state change here so audit-logging and idempotency stay
 * centralized.
 */
class UserInviteService
{
    /** Default invite lifetime, per plan §WS-A.1. */
    public const DEFAULT_TTL_HOURS = 72;

    /**
     * @param  array{employee_id:int, email:string, role_id?:int|null}  $data
     */
    public function invite(array $data, User $inviter): UserInvite
    {
        return DB::transaction(function () use ($data, $inviter) {
            $employee = Employee::query()->whereKey($data['employee_id'])->firstOrFail();

            return UserInvite::create([
                'employee_id' => $employee->id,
                'email'       => $data['email'],
                'token'       => bin2hex(random_bytes(32)),
                'role_id'     => $data['role_id'] ?? null,
                'expires_at'  => Carbon::now()->addHours(self::DEFAULT_TTL_HOURS),
                'invited_by'  => $inviter->id,
            ]);
        });
    }

    /**
     * @param  array{token:string, name:string, password:string}  $data
     */
    public function accept(array $data): User
    {
        return DB::transaction(function () use ($data) {
            // Whitespace-tolerant lookup; missing token => 404 (not 410) so
            // that revoked or unknown tokens look identical to attackers.
            $invite = UserInvite::query()
                ->where('token', $data['token'])
                ->whereNull('deleted_at')
                ->first();

            if ($invite === null) {
                throw new HttpException(404, 'Invite not found.');
            }

            if ($invite->isUsed() || $invite->isExpired()) {
                // 410 Gone — explicit signal to the SPA so it can show
                // "this link is no longer valid".
                throw new HttpException(410, 'This invite is no longer valid.');
            }

            // Eager-load position because the strict-mode lazy-loading guard
            // (preventLazyLoading) is on outside production.
            $employee = Employee::query()->with('position')
                ->whereKey($invite->employee_id)->firstOrFail();

            // Re-check at acceptance time — someone may have issued a user
            // through another flow between invitation and acceptance.
            if (User::query()->where('employee_id', $employee->id)->exists()) {
                throw new HttpException(409, 'This employee already has a portal account.');
            }

            $roleId = $invite->role_id
                ?? $employee->position?->default_role_id
                ?? Role::query()->where('slug', 'employee')->value('id');

            if ($roleId === null) {
                // Should never happen in seeded environments — keep an
                // explicit guard rail for unseeded test setups.
                throw new HttpException(422, 'No role available to assign to this user.');
            }

            $user = User::create([
                'name'                => $data['name'],
                'email'               => $invite->email,
                'password'            => bcrypt($data['password']),
                'role_id'             => $roleId,
                'employee_id'         => $employee->id,
                'is_active'           => true,
                'must_change_password' => false,
                'password_changed_at' => Carbon::now(),
            ]);

            $invite->forceFill(['used_at' => Carbon::now()])->save();

            return $user;
        });
    }

    public function revoke(UserInvite $invite): void
    {
        // Soft-delete acts as our revocation flag — preserves history while
        // making the token unusable in `accept()`.
        $invite->delete();
    }

    /**
     * @param  array{status?:string, search?:string, per_page?:int}  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = UserInvite::query()
            ->with(['employee', 'role', 'inviter'])
            ->orderByDesc('created_at');

        $status = $filters['status'] ?? 'pending';
        match ($status) {
            'pending'  => $query->whereNull('used_at')
                ->whereNull('deleted_at')
                ->where('expires_at', '>', now()),
            'used'     => $query->whereNotNull('used_at'),
            'expired'  => $query->whereNull('used_at')->where('expires_at', '<=', now()),
            'revoked'  => $query->onlyTrashed(),
            default    => null,
        };

        if (! empty($filters['search'])) {
            $query->where('email', 'like', '%'.$filters['search'].'%');
        }

        return $query->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }
}
