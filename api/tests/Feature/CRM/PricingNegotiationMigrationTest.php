<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\Product;
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

    public function test_hardening_upgrade_adds_finance_reads_without_changing_custom_grants(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $migration = require database_path('migrations/2026_10_04_000006_harden_cross_module_pricing_reviews.php');
        $migration->down();
        $finance = DB::table('roles')->where('slug', 'finance_officer')->value('id');
        $reads = DB::table('permissions')->whereIn('slug', ['crm.products.view', 'crm.price_agreements.view'])->pluck('id');
        DB::table('role_permissions')->where('role_id', $finance)->whereIn('permission_id', $reads)->delete();
        $before = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])->all();
        $migration->up();
        $this->assertSame(2, DB::table('role_permissions')->where('role_id', $finance)->whereIn('permission_id', $reads)->count());
        $after = DB::table('role_permissions')->whereNot(fn ($query) => $query->where('role_id', $finance)->whereIn('permission_id', $reads))->orderBy('role_id')->orderBy('permission_id')->get(['role_id', 'permission_id'])->all();
        $this->assertEquals($before, $after);
        $product = Product::factory()->create();
        $this->assertNull($product->financial_change_request);
        $product->forceFill(['financial_change_request' => ['notes' => 'Preserve pending proposal']])->save();
        try {
            $migration->down();
            $this->fail('Rollback must refuse a pending proposal.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Keep review histories', $error->getMessage());
        }
        $this->assertSame('Preserve pending proposal', $product->fresh()->financial_change_request['notes']);
    }
}
