<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Events\PortalNotificationChanged;
use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Models\OutboxMessage;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxDispatcher;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalNotificationRealtimeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $transactions = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
        app(SettingsService::class)->set('security.password_expiry_days', 0, 'security');
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        Event::fake([PortalNotificationChanged::class]);
        Bus::fake([DispatchOutboxMessage::class]);
        require base_path('routes/channels.php');
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_portal_notice_signal_is_private_minimal_deduplicated_and_after_commit(): void
    {
        $customer = CustomerPortalUser::create([
            'customer_id' => Customer::factory()->create(['is_active' => true])->id,
            'name' => 'Realtime customer',
            'email' => Str::uuid().'@portal.test',
            'password' => 'Password123!',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $supplier = SupplierPortalUser::factory()->create(['must_change_password' => false]);
        $service = app(NotificationService::class);

        DB::transaction(function () use ($customer, $supplier, $service): void {
            $service->sendPortalInApp([$customer, $supplier], 'portal.test', ['title' => 'Private notice'], 'portal:notice:1');
            $service->sendPortalInApp([$customer, $supplier], 'portal.test', ['title' => 'Private notice'], 'portal:notice:1');

            Event::assertNotDispatched(PortalNotificationChanged::class);
        });

        $messages = OutboxMessage::query()
            ->where('event_type', PortalNotificationChanged::class)
            ->orderBy('created_at')
            ->get();
        self::assertCount(2, $messages);
        foreach ($messages as $message) {
            app(OutboxDispatcher::class)->dispatch((string) $message->getKey());
        }

        Event::assertDispatchedTimes(PortalNotificationChanged::class, 2);
        Event::assertDispatched(PortalNotificationChanged::class, static fn (PortalNotificationChanged $event): bool => $event->realm === 'customer'
            && $event->userHashId === $customer->hash_id
            && $event->action === 'created'
            && $event->broadcastAs() === 'portal.notification.changed'
            && array_keys($event->broadcastWith()) === ['action', 'event_id', 'id', 'type']
            && $event->broadcastOn()[0]->name === "private-portal.customer.user.{$customer->hash_id}"
        );
        Event::assertDispatched(PortalNotificationChanged::class, static fn (PortalNotificationChanged $event): bool => $event->realm === 'supplier'
            && $event->userHashId === $supplier->hash_id
            && $event->action === 'created'
            && $event->broadcastOn()[0]->name === "private-portal.supplier.user.{$supplier->hash_id}"
        );
    }

    public function test_portal_notice_signal_is_not_emitted_when_the_transaction_rolls_back(): void
    {
        $customer = CustomerPortalUser::create([
            'customer_id' => Customer::factory()->create(['is_active' => true])->id,
            'name' => 'Rollback customer',
            'email' => Str::uuid().'@portal.test',
            'password' => 'Password123!',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        try {
            DB::transaction(function () use ($customer): void {
                app(NotificationService::class)->sendPortalInApp([$customer], 'portal.test', ['title' => 'Rolled back'], 'portal:notice:rollback');
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('Rollback', $exception->getMessage());
        }

        Event::assertNotDispatched(PortalNotificationChanged::class);
    }

    public function test_customer_and_supplier_private_notification_channels_only_authorize_the_session_owner(): void
    {
        $customer = CustomerPortalUser::create([
            'customer_id' => Customer::factory()->create(['is_active' => true])->id,
            'name' => 'Customer channel owner',
            'email' => Str::uuid().'@portal.test',
            'password' => 'Password123!',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $otherCustomer = CustomerPortalUser::create([
            'customer_id' => $customer->customer_id,
            'name' => 'Other customer portal user',
            'email' => Str::uuid().'@portal.test',
            'password' => 'Password123!',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        self::assertNotSame($customer->hash_id, $otherCustomer->hash_id);
        self::assertSame($customer->id, CustomerPortalUser::tryDecodeHash($customer->hash_id));
        self::assertSame($otherCustomer->id, CustomerPortalUser::tryDecodeHash($otherCustomer->hash_id));

        $this->actingAs($customer, 'customer_portal')
            ->postJson('/api/v1/b2b/customer/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-portal.customer.user.{$customer->hash_id}",
            ])
            ->assertOk();
        $this->postJson('/api/v1/b2b/customer/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-portal.customer.user.{$otherCustomer->hash_id}",
        ])->assertForbidden();

        $supplier = SupplierPortalUser::factory()->create(['must_change_password' => false]);
        $otherSupplier = SupplierPortalUser::factory()->create([
            'vendor_id' => $supplier->vendor_id,
            'must_change_password' => false,
        ]);
        $this->actingAs($supplier, 'supplier_portal')
            ->postJson('/api/v1/b2b/supplier/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-portal.supplier.user.{$supplier->hash_id}",
            ])
            ->assertOk();
        $this->postJson('/api/v1/b2b/supplier/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-portal.supplier.user.{$otherSupplier->hash_id}",
        ])->assertForbidden();
    }

    public function test_portal_read_and_delete_actions_ping_other_tabs_after_the_write(): void
    {
        $customer = CustomerPortalUser::create([
            'customer_id' => Customer::factory()->create(['is_active' => true])->id,
            'name' => 'Notification action owner',
            'email' => Str::uuid().'@portal.test',
            'password' => 'Password123!',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $notificationId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => 'portal.test',
            'notifiable_type' => CustomerPortalUser::class,
            'notifiable_id' => $customer->id,
            'data' => json_encode(['title' => 'Portal notice']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($customer, 'customer_portal')
            ->patchJson("/api/v1/b2b/customer/notifications/{$notificationId}/read")
            ->assertOk();
        $readMessage = OutboxMessage::query()
            ->where('event_type', PortalNotificationChanged::class)
            ->where('payload->action', 'read')
            ->latest('created_at')
            ->firstOrFail();
        app(OutboxDispatcher::class)->dispatch((string) $readMessage->getKey());
        Event::assertDispatched(PortalNotificationChanged::class, static fn (PortalNotificationChanged $event): bool => $event->realm === 'customer'
            && $event->userHashId === $customer->hash_id
            && $event->action === 'read'
            && $event->notificationId === $notificationId
        );

        $this->deleteJson("/api/v1/b2b/customer/notifications/{$notificationId}")->assertOk();
        $deleteMessage = OutboxMessage::query()
            ->where('event_type', PortalNotificationChanged::class)
            ->where('payload->action', 'deleted')
            ->latest('created_at')
            ->firstOrFail();
        app(OutboxDispatcher::class)->dispatch((string) $deleteMessage->getKey());
        Event::assertDispatched(PortalNotificationChanged::class, static fn (PortalNotificationChanged $event): bool => $event->realm === 'customer'
            && $event->userHashId === $customer->hash_id
            && $event->action === 'deleted'
            && $event->notificationId === $notificationId
        );
    }

    public function test_portal_notification_read_change_is_recorded_in_the_durable_outbox(): void
    {
        $customer = CustomerPortalUser::create([
            'customer_id' => Customer::factory()->create(['is_active' => true])->id,
            'name' => 'Outbox customer',
            'email' => Str::uuid().'@portal.test',
            'password' => 'Password123!',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $notificationId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => 'portal.test',
            'notifiable_type' => CustomerPortalUser::class,
            'notifiable_id' => $customer->id,
            'data' => json_encode(['title' => 'Portal notice']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($customer, 'customer_portal')
            ->patchJson("/api/v1/b2b/customer/notifications/{$notificationId}/read")
            ->assertOk();

        $message = OutboxMessage::query()
            ->where('event_type', PortalNotificationChanged::class)
            ->latest('created_at')
            ->first();

        self::assertNotNull($message, 'Committed portal notification changes must have a durable refresh message.');
        self::assertSame(OutboxMessage::STATUS_PENDING, $message->status);
        self::assertSame('customer', $message->payload['realm']);
        self::assertSame($customer->hash_id, $message->payload['userHashId']);
        self::assertSame('read', $message->payload['action']);
        self::assertSame($notificationId, $message->payload['notificationId']);
        self::assertArrayNotHasKey('data', $message->payload);
        app(OutboxDispatcher::class)->dispatch((string) $message->getKey());
        Event::assertDispatched(PortalNotificationChanged::class, static fn (PortalNotificationChanged $event): bool => $event->realm === 'customer'
            && $event->userHashId === $customer->hash_id
            && $event->action === 'read'
            && $event->notificationId === $notificationId
        );
    }

    public function test_portal_bulk_notification_actions_stage_one_outbox_ping_per_action(): void
    {
        $customer = CustomerPortalUser::create([
            'customer_id' => Customer::factory()->create(['is_active' => true])->id,
            'name' => 'Bulk outbox customer',
            'email' => Str::uuid().'@portal.test',
            'password' => 'Password123!',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $unreadIds = [(string) Str::uuid(), (string) Str::uuid()];
        $rows = [];
        foreach ([...$unreadIds, (string) Str::uuid()] as $index => $id) {
            $rows[] = [
                'id' => $id,
                'type' => 'portal.test',
                'notifiable_type' => CustomerPortalUser::class,
                'notifiable_id' => $customer->id,
                'data' => json_encode(['title' => 'Portal notice']),
                'read_at' => $index < 2 ? null : now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('notifications')->insert($rows);

        $this->actingAs($customer, 'customer_portal')
            ->patchJson('/api/v1/b2b/customer/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_read', 2);

        $readMessage = OutboxMessage::query()
            ->where('event_type', PortalNotificationChanged::class)
            ->where('payload->action', 'read')
            ->firstOrFail();
        self::assertNull($readMessage->payload['notificationId']);
        app(OutboxDispatcher::class)->dispatch((string) $readMessage->getKey());

        $this->deleteJson('/api/v1/b2b/customer/notifications/clear-read')
            ->assertOk()
            ->assertJsonPath('data.deleted', 3);

        $deleteMessage = OutboxMessage::query()
            ->where('event_type', PortalNotificationChanged::class)
            ->where('payload->action', 'deleted')
            ->firstOrFail();
        self::assertNull($deleteMessage->payload['notificationId']);
        app(OutboxDispatcher::class)->dispatch((string) $deleteMessage->getKey());
        Event::assertDispatchedTimes(PortalNotificationChanged::class, 2);
    }
}
