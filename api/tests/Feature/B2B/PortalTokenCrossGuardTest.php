<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * OGAMI audit DEFECT-3 — a B2B portal bearer token must not bleed into the
 * internal SPA stack (auth:sanctum + session.timeout).
 *
 * Because the portal guards use the sanctum driver, a portal token's principal
 * can satisfy auth:sanctum on internal routes. The SessionTimeout middleware
 * then tried to write `last_activity` on a SupplierPortalUser (no such column)
 * and threw a SQL 500. The guard must instead reject any non-User principal
 * with a clean 401 — and never leak internal data.
 */
class PortalTokenCrossGuardTest extends TestCase
{
    use RefreshDatabase;

    private function portalUser(): SupplierPortalUser
    {
        $vendor = Vendor::factory()->create();

        return SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name'      => 'Portal Tester',
            'email'     => 'portal+'.uniqid().'@t.test',
            'password'  => Hash::make('SupplierPass-1!'),
            'is_active' => true,
        ]);
    }

    public function test_portal_token_on_auth_user_returns_401_not_500(): void
    {
        $portal = $this->portalUser();

        // Act as the portal principal on the sanctum guard (what a portal bearer
        // token resolves to) and hit the internal SPA identity endpoint.
        Sanctum::actingAs($portal, ['*']);

        $this->getJson('/api/v1/auth/user')
            ->assertStatus(401);
    }

    public function test_portal_token_cannot_reach_internal_module_routes(): void
    {
        $portal = $this->portalUser();
        Sanctum::actingAs($portal, ['*']);

        // Permission-gated employee route: must be denied (401/403), never 500,
        // never a 200 with leaked data.
        $status = $this->getJson('/api/v1/hr/employees')->getStatusCode();

        $this->assertContains($status, [401, 403],
            "Portal token reaching /hr/employees should be 401/403, got {$status}");
    }
}
