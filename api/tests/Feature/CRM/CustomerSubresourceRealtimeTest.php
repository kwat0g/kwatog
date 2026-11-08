<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Services\SettingsService;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Accounting\Events\CustomerChanged;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\CustomerProductWarrantyOverride;
use App\Modules\CRM\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerSubresourceRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        app(SettingsService::class)->set('modules.crm', true, 'modules');
        Storage::fake('local');
    }

    public function test_agreement_document_upload_stages_a_content_free_customer_refresh(): void
    {
        $customer = Customer::factory()->create();
        $actor = $this->actor();
        $this->clearCustomerSignals();

        $response = $this->actingAs($actor)->post(
            "/api/v1/crm/customers/{$customer->hash_id}/agreement-documents",
            ['file' => UploadedFile::fake()->create('terms.pdf', 8, 'application/pdf')],
        );

        $response->assertCreated();
        $this->assertDatabaseCount('customer_agreement_documents', 1);
        $signal = $this->customerSignal($customer);
        $this->assertSame('updated', $signal->payload['action']);
        $this->assertSame($customer->hash_id, $signal->payload['customerHashId']);
        $this->assertStringNotContainsString('file_path', json_encode($signal->payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('filename', json_encode($signal->payload, JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('event_outbox')->where('event_type', CustomerChanged::class)->count());
    }

    public function test_warranty_terms_upsert_stages_a_customer_refresh(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $actor = $this->actor();
        $this->clearCustomerSignals();

        $this->actingAs($actor)
            ->putJson("/api/v1/crm/customers/{$customer->hash_id}/warranty-terms/{$product->hash_id}", [
                'warranty_duration_days' => 365,
                'warranty_start_basis' => 'delivery',
            ])
            ->assertOk()
            ->assertJsonPath('data.warranty_duration_days', 365);

        $signal = $this->customerSignal($customer);
        $this->assertSame('updated', $signal->payload['action']);
        $this->assertSame($customer->hash_id, $signal->payload['customerHashId']);
        $this->assertSame(1, DB::table('event_outbox')->where('event_type', CustomerChanged::class)->count());
    }

    public function test_existing_warranty_terms_update_stages_a_customer_refresh(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        CustomerProductWarrantyOverride::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'warranty_duration_days' => 180,
        ]);
        $actor = $this->actor();
        $this->clearCustomerSignals();

        $this->actingAs($actor)
            ->putJson("/api/v1/crm/customers/{$customer->hash_id}/warranty-terms/{$product->hash_id}", [
                'warranty_duration_days' => 365,
                'warranty_start_basis' => 'delivery',
            ])
            ->assertOk()
            ->assertJsonPath('data.warranty_duration_days', 365);

        $signal = $this->customerSignal($customer);
        $this->assertSame('updated', $signal->payload['action']);
        $this->assertSame($customer->hash_id, $signal->payload['customerHashId']);
        $this->assertSame(1, DB::table('event_outbox')->where('event_type', CustomerChanged::class)->count());
    }

    public function test_warranty_terms_delete_stages_a_customer_refresh(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        CustomerProductWarrantyOverride::query()->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'warranty_duration_days' => 365,
        ]);
        $actor = $this->actor();
        $this->clearCustomerSignals();

        $this->actingAs($actor)
            ->deleteJson("/api/v1/crm/customers/{$customer->hash_id}/warranty-terms/{$product->hash_id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('customer_product_warranty_overrides', [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
        ]);
        $signal = $this->customerSignal($customer);
        $this->assertSame('updated', $signal->payload['action']);
        $this->assertSame($customer->hash_id, $signal->payload['customerHashId']);
        $this->assertSame(1, DB::table('event_outbox')->where('event_type', CustomerChanged::class)->count());
    }

    public function test_coalesced_parent_and_internal_child_updates_preserve_the_profile_audience(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $this->clearCustomerSignals();

        DB::transaction(function () use ($customer, $product): void {
            $customer->update(['name' => $customer->name.' Updated']);
            CustomerProductWarrantyOverride::query()->create([
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'warranty_duration_days' => 365,
            ]);
        });

        $row = DB::table('event_outbox')->where('event_type', CustomerChanged::class)->first();
        $this->assertNotNull($row, 'The combined transaction must stage one coalesced customer refresh.');
        $event = app(OutboxEventCodec::class)->decode(
            CustomerChanged::class,
            json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        $channels = array_map(static fn ($channel): string => $channel->name, $event->broadcastOn());

        $this->assertSame(1, DB::table('event_outbox')->where('event_type', CustomerChanged::class)->count());
        $this->assertContains('private-entity.customer.visible', $channels);
        $this->assertContains("private-accounting.customer.{$customer->hash_id}", $channels);
        $this->assertContains("private-customer.profile.{$customer->hash_id}", $channels);
    }

    private function actor(): User
    {
        $role = Role::create(['name' => 'Customer subresource '.uniqid(), 'slug' => 'customer_subresource_'.uniqid()]);
        $permission = Permission::firstOrCreate(
            ['slug' => 'accounting.customers.manage'],
            ['name' => 'Manage customers', 'module' => 'crm'],
        );
        $role->permissions()->attach($permission->id);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function clearCustomerSignals(): void
    {
        DB::table('event_outbox')->where('event_type', CustomerChanged::class)->delete();
    }

    private function customerSignal(Customer $customer): object
    {
        $row = DB::table('event_outbox')
            ->where('event_type', CustomerChanged::class)
            ->first();

        $this->assertNotNull($row, 'The customer mutation must stage one CustomerChanged outbox row.');
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($customer->hash_id, $payload['customerHashId'] ?? null);
        $event = app(OutboxEventCodec::class)->decode(CustomerChanged::class, $payload);
        $channels = array_map(static fn ($channel): string => $channel->name, $event->broadcastOn());
        $this->assertArrayNotHasKey('includeCustomerProfile', $event->broadcastWith());
        $this->assertSame([
            'private-entity.customer.visible',
            "private-accounting.customer.{$customer->hash_id}",
        ], $channels, 'Internal-only customer child writes must not notify the customer portal profile channel.');

        return (object) ['payload' => $payload];
    }
}
