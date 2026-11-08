<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Services\OutboxEventCodec;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\PriceRequestStatus;
use App\Modules\CRM\Events\PriceRequestChanged;
use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class PriceRequestRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
        app(SettingsService::class)->set('modules.crm', true, 'modules');
        app(SettingsService::class)->set('modules.b2b_portals', true, 'modules');
    }

    public function test_model_observer_coalesces_transaction_writes_into_a_scoped_refresh_signal(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $buyer = $this->portalUser($customer);

        $request = DB::transaction(function () use ($buyer, $customer, $product): PriceRequest {
            $request = PriceRequest::query()->create([
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'requested_by' => $buyer->id,
                'quantity' => 100,
                'requested_price' => '30.00',
                'notes' => 'Confidential request notes',
                'status' => PriceRequestStatus::Requested,
                'version' => 1,
            ]);

            $request->forceFill([
                'status' => PriceRequestStatus::Offered,
                'offer' => ['price' => '32.00'],
                'version' => 2,
            ])->save();

            return $request;
        });

        $rows = DB::table('event_outbox')->where('event_type', PriceRequestChanged::class)->get();
        $this->assertCount(1, $rows, 'Create and update in one transaction should leave one coalesced refresh.');

        $encodedPayload = json_decode((string) $rows->first()->payload, true, 512, JSON_THROW_ON_ERROR);
        $event = app(OutboxEventCodec::class)->decode(PriceRequestChanged::class, $encodedPayload);
        $channels = array_map(static fn ($channel): string => $channel->name, $event->broadcastOn());
        $payload = $event->broadcastWith();

        $this->assertSame([
            "private-crm.price-request.{$request->hash_id}",
            'private-entity.price_request.visible',
            "private-customer.price-request.{$customer->hash_id}",
        ], $channels);
        $this->assertSame('price_request', $payload['entity_type']);
        $this->assertSame($request->hash_id, $payload['entity_id']);
        $this->assertSame('updated', $payload['action']);
        $this->assertSame('2', $payload['version']);
        $this->assertSame($buyer->hash_id, $payload['actor_id']);
        $this->assertArrayNotHasKey('customer_id', $payload);
        $this->assertArrayNotHasKey('quantity', $payload);
        $this->assertArrayNotHasKey('price', $payload);
        $this->assertArrayNotHasKey('notes', $payload);
        $this->assertStringNotContainsString('Confidential request notes', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('32.00', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_request_refresh_outbox_row_rolls_back_with_the_business_transaction(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $buyer = $this->portalUser($customer);

        try {
            DB::transaction(function () use ($buyer, $customer, $product): void {
                PriceRequest::query()->create([
                    'customer_id' => $customer->id,
                    'product_id' => $product->id,
                    'requested_by' => $buyer->id,
                    'quantity' => 100,
                    'requested_price' => '30.00',
                    'notes' => 'Rollback check',
                    'status' => PriceRequestStatus::Requested,
                    'version' => 1,
                ]);

                throw new RuntimeException('Roll back the request and its refresh signal.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Roll back the request and its refresh signal.', $exception->getMessage());
        }

        $this->assertDatabaseCount('price_requests', 0);
        $this->assertSame(0, DB::table('event_outbox')->where('event_type', PriceRequestChanged::class)->count());
    }

    public function test_private_channels_enforce_internal_permission_and_customer_account_scope(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $buyer = $this->portalUser($customer);
        $request = PriceRequest::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'requested_by' => $buyer->id,
            'quantity' => 100,
            'requested_price' => '30.00',
            'notes' => 'Channel authorization fixture',
            'status' => PriceRequestStatus::Requested,
            'version' => 1,
        ]);
        $reader = $this->userWithPermission('crm.price_agreements.propose');
        $unrelated = $this->userWithPermission('inventory.view');
        $sameAccountBuyer = $this->portalUser($customer);
        $otherAccountBuyer = $this->portalUser(Customer::factory()->create());

        $this->authorizeChannel($reader, "private-crm.price-request.{$request->hash_id}")->assertOk();
        $this->authorizeChannel($reader, 'private-entity.price_request.visible')->assertOk();
        $this->authorizeChannel($unrelated, "private-crm.price-request.{$request->hash_id}")->assertForbidden();
        $this->authorizeChannel($unrelated, 'private-entity.price_request.visible')->assertForbidden();
        $this->authorizeChannel($reader, 'private-crm.price-request.invalid')->assertForbidden();

        $this->actingAs($sameAccountBuyer, 'customer_portal')
            ->postJson('/api/v1/b2b/customer/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-customer.price-request.{$customer->hash_id}",
            ])
            ->assertOk();
        $this->actingAs($otherAccountBuyer, 'customer_portal')
            ->postJson('/api/v1/b2b/customer/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-customer.price-request.{$customer->hash_id}",
            ])
            ->assertForbidden();

        app(SettingsService::class)->set('modules.crm', false, 'modules');
        $this->authorizeChannel($reader, "private-crm.price-request.{$request->hash_id}")->assertForbidden();
        $this->authorizeChannel($reader, 'private-entity.price_request.visible')->assertForbidden();
    }

    private function portalUser(Customer $customer): CustomerPortalUser
    {
        return CustomerPortalUser::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Pricing buyer',
            'email' => uniqid('price-request-realtime-', true).'@test.local',
            'password' => 'password',
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
    }

    private function userWithPermission(string $permissionSlug): User
    {
        $role = Role::query()->create([
            'name' => 'Price request channel '.uniqid(),
            'slug' => 'price_request_channel_'.uniqid(),
        ]);
        $permission = Permission::query()->where('slug', $permissionSlug)->firstOrFail();
        $role->permissions()->attach($permission->id);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function authorizeChannel(User $user, string $channel)
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }
}
