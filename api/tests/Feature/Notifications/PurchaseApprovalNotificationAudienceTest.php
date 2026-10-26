<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Common\Models\ApprovalRecord;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Events\PurchaseOrderSubmitted;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseOrderApproved as NotifyPurchaseOrderApproved;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseOrderSubmitted;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseRequestApproved as NotifyPurchaseRequestApproved;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseApprovalNotificationAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_pr_approved_notice_excludes_user_without_route_view_permission(): void
    {
        app(SettingsService::class)->set('modules.purchasing', true, 'modules');
        [$eligible, $revoked] = $this->purchasingOfficers();
        $this->revoke($revoked, 'purchasing.view', $eligible);
        $pr = PurchaseRequest::factory()->create();

        app(NotifyPurchaseRequestApproved::class)->handle(new PurchaseRequestApproved($pr));

        $this->assertNotice('chain.pr_approved', $eligible, true);
        $this->assertNotice('chain.pr_approved', $revoked, false);
        $data = json_decode((string) DB::table('notifications')
            ->where('notifiable_id', $eligible->id)
            ->where('type', 'chain.pr_approved')
            ->value('data'), true);
        $this->assertSame(PurchaseRequest::class, $data['subject_type']);
        $this->assertSame((string) $pr->id, $data['subject_id']);
    }

    public function test_po_approved_notice_excludes_user_without_route_view_permission(): void
    {
        [$eligible, $revoked] = $this->purchasingOfficers();
        $this->revoke($revoked, 'purchasing.view', $eligible);
        $po = PurchaseOrder::factory()->create();

        app(NotifyPurchaseOrderApproved::class)->handle(new PurchaseOrderApproved($po));

        $this->assertNotice('chain.po_approved', $eligible, true);
        $this->assertNotice('chain.po_approved', $revoked, false);
    }

    public function test_po_pending_notice_uses_effective_approval_permission(): void
    {
        [$eligible, $revoked] = $this->usersWithRole('finance_officer');
        $this->revoke($revoked, 'purchasing.po.approve', $eligible);
        $po = PurchaseOrder::factory()->create();
        ApprovalRecord::query()->create([
            'approvable_type' => $po->getMorphClass(),
            'approvable_id' => $po->id,
            'step_order' => 1,
            'attempt' => 1,
            'is_current' => true,
            'role_slug' => 'finance_officer',
            'action' => 'pending',
            'created_at' => now(),
        ]);

        app(NotifyOnPurchaseOrderSubmitted::class)->handle(new PurchaseOrderSubmitted($po));

        $this->assertNotice('purchasing.po_pending_approval', $eligible, true);
        $this->assertNotice('purchasing.po_pending_approval', $revoked, false);
    }

    /** @return array{User, User} */
    private function purchasingOfficers(): array
    {
        return $this->usersWithRole('purchasing_officer');
    }

    /** @return array{User, User} */
    private function usersWithRole(string $roleSlug): array
    {
        $roleId = Role::query()->where('slug', $roleSlug)->value('id');

        return [
            User::factory()->create(['role_id' => $roleId, 'is_active' => true]),
            User::factory()->create(['role_id' => $roleId, 'is_active' => true]),
        ];
    }

    private function revoke(User $user, string $slug, User $grantedBy): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => Permission::query()->where('slug', $slug)->value('id'),
            'type' => 'revoke',
            'granted_by' => $grantedBy->id,
            'reason' => 'Purchase approval notification audience regression test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->flushPermissionsCache();
    }

    private function assertNotice(string $type, User $user, bool $present): void
    {
        $data = [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'type' => $type,
        ];
        if ($present) {
            $this->assertDatabaseHas('notifications', $data);
        } else {
            $this->assertDatabaseMissing('notifications', $data);
        }
    }
}
