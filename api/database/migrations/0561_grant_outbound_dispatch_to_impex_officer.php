<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * O2C audit 2026-09-25 — outbound delivery dispatch had no business owner.
 * `supply_chain.deliveries.create` / `.confirm` were held by no seeded role,
 * so assigning a van and driver, moving a shipment and confirming it on a
 * signed DR needed the IT administrator. ImpEx already runs the fleet; it now
 * owns dispatch. Finance gains the delivery read so the auto-invoice failure
 * notice, which links to the delivery's Retry invoice action, stops 403ing.
 *
 * Mirrors RolePermissionSeeder. On a fresh install there are no roles yet and
 * the seeder grants these; an existing role with a missing permission row is
 * drift and fails loudly instead of silently granting nothing.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const GRANTS = [
        'impex_officer' => ['supply_chain.deliveries.create', 'supply_chain.deliveries.confirm'],
        'finance_officer' => ['supply_chain.deliveries.view'],
    ];

    public function up(): void
    {
        foreach ($this->pairs() as [$roleId, $permissionId]) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    public function down(): void
    {
        foreach ($this->pairs() as [$roleId, $permissionId]) {
            DB::table('role_permissions')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->delete();
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    /** @return list<array{int, int}> */
    private function pairs(): array
    {
        $pairs = [];
        foreach (self::GRANTS as $roleSlug => $permissionSlugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if ($roleId === null) {
                continue;
            }
            foreach ($permissionSlugs as $slug) {
                $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
                if ($permissionId === null) {
                    throw new RuntimeException("Permission {$slug} is missing; re-run RolePermissionSeeder before granting it to {$roleSlug}.");
                }
                $pairs[] = [(int) $roleId, (int) $permissionId];
            }
        }

        return $pairs;
    }
};
