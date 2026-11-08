<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Services\OutboxService;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\ClearanceChanged;
use App\Modules\HR\Models\Clearance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, scoped, transaction-coalesced refresh publisher for clearances. */
final class ClearanceRealtimeService
{
    public function stage(Clearance $clearance, string $action): void
    {
        $access = app(SeparationService::class);
        $roleIds = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.slug', 'hr.separation.view')
            ->pluck('role_permissions.role_id');
        $systemAdminRoleId = Role::query()->where('slug', 'system_admin')->value('id');
        $grantedUserIds = UserPermissionOverride::query()
            ->where('type', PermissionOverrideType::Grant->value)
            ->whereHas('permission', static fn ($query) => $query->where('slug', 'hr.separation.view'))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('user_id');

        $viewerUserHashIds = User::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereIn('role_id', $roleIds)
                ->when($systemAdminRoleId !== null, fn ($query) => $query->orWhere('role_id', $systemAdminRoleId))
                ->orWhereIn('id', $grantedUserIds))
            ->get()
            ->filter(static fn (User $user): bool => $access->canView($clearance, $user))
            ->map(static fn (User $user): string => (string) $user->hash_id)
            ->values()
            ->all();

        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new ClearanceChanged(
            hashId: (string) $clearance->hash_id,
            action: $action,
            version: $clearance->updated_at?->format('Y-m-d\TH:i:s.uP') ?? now()->format('Y-m-d\TH:i:s.uP'),
            actorId: auth()->user()?->hash_id,
            viewerUserHashIds: $viewerUserHashIds,
            timestamp: now()->toISOString(),
        ), "hr-clearance-refresh:{$clearance->getKey()}:{$transactionId}");
    }
}
