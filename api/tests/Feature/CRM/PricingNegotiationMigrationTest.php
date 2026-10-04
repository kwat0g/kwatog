<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PricingNegotiationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_and_rollback_preserve_preexisting_permission_rows_and_grants(): void
    {
        $migration = require database_path('migrations/2026_10_04_000005_add_pricing_negotiation_and_cost_reviews.php');

        $this->seed(RolePermissionSeeder::class);
        $migration->down();
        $this->seed(RolePermissionSeeder::class);

        $slugs = [
            'crm.price_agreements.propose',
            'crm.price_agreements.approve',
            'inventory.costs.review',
            'inventory.costs.approve',
        ];
        $permissionsBefore = DB::table('permissions')
            ->whereIn('slug', $slugs)
            ->orderBy('slug')
            ->get(['slug', 'id', 'name', 'module'])
            ->all();
        $grantsBefore = DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('p.slug', $slugs)
            ->orderBy('p.slug')
            ->orderBy('rp.role_id')
            ->get(['p.slug', 'rp.role_id'])
            ->all();

        $migration->up();
        $migration->down();

        $permissionsAfter = DB::table('permissions')
            ->whereIn('slug', $slugs)
            ->orderBy('slug')
            ->get(['slug', 'id', 'name', 'module'])
            ->all();
        $grantsAfter = DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('p.slug', $slugs)
            ->orderBy('p.slug')
            ->orderBy('rp.role_id')
            ->get(['p.slug', 'rp.role_id'])
            ->all();

        $this->assertEquals($permissionsBefore, $permissionsAfter);
        $this->assertEquals($grantsBefore, $grantsAfter);
    }
}
