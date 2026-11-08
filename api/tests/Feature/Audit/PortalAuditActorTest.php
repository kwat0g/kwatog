<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Common\Services\SystemUserResolver;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * HasAuditLog must not credit a portal user's write to the employee who
 * happens to share the numeric id. Under a portal guard Auth::id() is a
 * supplier_portal_users key; the old check only asked whether a users row with
 * that id existed.
 */
class PortalAuditActorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    private function auditFor(Vendor $vendor): AuditLog
    {
        return AuditLog::query()
            ->where('model_type', $vendor->getMorphClass())
            ->where('model_id', $vendor->id)
            ->where('action', 'created')
            ->firstOrFail();
    }

    public function test_portal_write_is_not_attributed_to_the_employee_sharing_its_id(): void
    {
        $employee = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $portalUser = SupplierPortalUser::forceCreate([
            'id'        => $employee->id,
            'vendor_id' => $vendor->id,
            'name'      => 'Collide-'.substr(uniqid(), -5),
            'email'     => 'collide-'.uniqid().'@t.test',
            'password'  => bcrypt('Password1!'),
            'is_active' => true,
        ]);
        $this->assertSame($employee->id, $portalUser->id);

        Auth::shouldUse('supplier_portal');
        Auth::guard('supplier_portal')->setUser($portalUser);

        $written = Vendor::factory()->create();
        $audit = $this->auditFor($written);

        $this->assertNull($audit->user_id);
        $this->assertSame('supplier_portal', $audit->actor_type);
    }

    public function test_internal_user_write_still_records_the_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $audit = $this->auditFor(Vendor::factory()->create());

        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('user', $audit->actor_type);
    }

    public function test_impersonated_portal_write_records_the_system_user(): void
    {
        $vendor = Vendor::factory()->create();
        $portalUser = SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id]);
        Auth::shouldUse('supplier_portal');
        Auth::guard('supplier_portal')->setUser($portalUser);

        $resolver = app(SystemUserResolver::class);
        $written = $resolver->impersonate(fn (): Vendor => Vendor::factory()->create());
        $audit = $this->auditFor($written);

        $this->assertSame($resolver->id(), $audit->user_id);
        $this->assertSame('user', $audit->actor_type);
    }

    public function test_system_user_cache_is_identity_scoped_and_expires(): void
    {
        $settings = app(SettingsService::class);
        $email = 'service-account-'.uniqid().'@t.test';
        $settings->set('system_user.email', $email, 'system_user');
        $settings->set('system_user.name', 'Scoped service account', 'system_user');

        $oldUser = User::factory()->create();
        Cache::forever(SystemUserResolver::CACHE_KEY, $oldUser->id);

        $resolvedId = app(SystemUserResolver::class)->id();

        $this->assertNotSame($oldUser->id, $resolvedId);
        $this->assertDatabaseHas('users', ['id' => $resolvedId, 'email' => $email]);
        $scopedKey = SystemUserResolver::CACHE_KEY.':'.hash('sha256', strtolower($email));
        $this->assertTrue(Cache::has($scopedKey));
        $this->assertFalse(Cache::has(SystemUserResolver::CACHE_KEY));

        $this->travel(2)->days();
        $this->assertFalse(Cache::has($scopedKey));
    }
}
