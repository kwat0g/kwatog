<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Events\UserNotificationCreated;
use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
        app(SettingsService::class)->set('security.password_expiry_days', 0, 'security');
        Event::fake([UserNotificationCreated::class]);
        Mail::fake();
    }

    private function customerUser(?Customer $customer = null): CustomerPortalUser
    {
        return CustomerPortalUser::create([
            'customer_id' => ($customer ?? Customer::factory()->create(['is_active' => true]))->id,
            'name' => 'Portal reader', 'email' => Str::uuid().'@portal.test',
            'password' => 'Password123!', 'is_active' => true, 'must_change_password' => false,
        ]);
    }

    private function notice(object $user, string $title = 'Review delivery proposal'): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'sales_order.delivery_proposed',
            'notifiable_type' => $user::class, 'notifiable_id' => $user->id,
            'data' => json_encode(['title' => $title, 'message' => 'Accept or decline the proposed date.']),
            'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_customer_inbox_excludes_other_users_even_with_matching_numeric_ids(): void
    {
        $user = $this->customerUser();
        $own = $this->notice($user);
        $this->notice($this->customerUser($user->customer));
        $this->notice($this->customerUser());
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'internal.secret',
            'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Internal secret']),
            'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'supplier.secret',
            'notifiable_type' => SupplierPortalUser::class, 'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Supplier secret']),
            'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user, 'customer_portal')->getJson('/api/v1/b2b/customer/notifications')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own)
            ->assertJsonPath('meta.unread_count', 1);
    }

    public function test_supplier_inbox_and_bulk_read_only_touch_authenticated_recipient(): void
    {
        $user = SupplierPortalUser::factory()->create(['must_change_password' => false]);
        $own = $this->notice($user);
        $other = $this->notice(SupplierPortalUser::factory()->create(['vendor_id' => $user->vendor_id]));
        $this->actingAs($user, 'supplier_portal')->getJson('/api/v1/b2b/supplier/notifications')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
        $this->patchJson('/api/v1/b2b/supplier/notifications/read-all')->assertOk()->assertJsonPath('data.marked_read', 1);
        $this->assertNotNull(DB::table('notifications')->where('id', $own)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $other)->value('read_at'));
    }

    public function test_foreign_notification_uuid_cannot_be_read_or_deleted(): void
    {
        $user = $this->customerUser();
        $foreign = $this->notice($this->customerUser());
        $this->actingAs($user, 'customer_portal')
            ->patchJson("/api/v1/b2b/customer/notifications/{$foreign}/read")->assertNotFound();
        $this->deleteJson("/api/v1/b2b/customer/notifications/{$foreign}")->assertNotFound();
        $this->assertNull(DB::table('notifications')->where('id', $foreign)->value('read_at'));
        $this->assertDatabaseHas('notifications', ['id' => $foreign]);
    }

    public function test_customer_can_read_and_clear_own_notice_without_clearing_unread(): void
    {
        $user = $this->customerUser();
        $read = $this->notice($user);
        $unread = $this->notice($user);
        $this->actingAs($user, 'customer_portal')
            ->patchJson("/api/v1/b2b/customer/notifications/{$read}/read")->assertOk();
        $this->deleteJson('/api/v1/b2b/customer/notifications/clear-read')->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertDatabaseMissing('notifications', ['id' => $read]);
        $this->assertDatabaseHas('notifications', ['id' => $unread, 'read_at' => null]);
    }

    public function test_sender_deduplicates_by_model_and_recipient_without_internal_channels(): void
    {
        $customer = $this->customerUser();
        $supplier = SupplierPortalUser::factory()->create();
        $internal = User::factory()->create();
        $sender = app(NotificationService::class);
        $data = ['title' => 'Delivery proposal', 'message' => 'Review proposed date.'];
        $sender->sendPortalInApp([$customer, $customer, $supplier, $internal], 'portal.test', $data, 'proposal:1');
        $sender->sendPortalInApp([$customer, $supplier], 'portal.test', $data, 'proposal:1');
        $this->assertSame(2, DB::table('notifications')->where('dedupe_key', 'proposal:1')->count());
        $portalData = json_decode((string) DB::table('notifications')->where('notifiable_type', CustomerPortalUser::class)->value('data'), true);
        $this->assertSame(1, $portalData['schema_version']);
        $this->assertSame('portal.test', $portalData['event_type']);
        $this->assertDatabaseHas('notifications', ['notifiable_type' => CustomerPortalUser::class, 'notifiable_id' => $customer->id, 'dedupe_key' => 'proposal:1']);
        $this->assertDatabaseHas('notifications', ['notifiable_type' => SupplierPortalUser::class, 'notifiable_id' => $supplier->id, 'dedupe_key' => 'proposal:1']);
        $this->assertDatabaseMissing('notifications', ['notifiable_type' => User::class, 'notifiable_id' => $internal->id, 'dedupe_key' => 'proposal:1']);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_portal_notice_rolls_back_with_business_transaction(): void
    {
        $user = $this->customerUser();
        try {
            DB::transaction(function () use ($user): void {
                app(NotificationService::class)->sendPortalInApp([$user], 'portal.test', ['title' => 'Rolled back'], 'rollback:1');
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Rollback', $e->getMessage());
        }
        $this->assertDatabaseMissing('notifications', ['dedupe_key' => 'rollback:1']);
    }

    public function test_portal_password_change_gate_also_protects_inbox(): void
    {
        $user = $this->customerUser();
        $user->update(['must_change_password' => true]);
        $this->actingAs($user, 'customer_portal')->getJson('/api/v1/b2b/customer/notifications')
            ->assertForbidden()->assertJsonPath('code', 'password_change_required');
    }
}
