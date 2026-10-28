<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderAgreement;
use App\Modules\CRM\Services\SalesOrderAgreementService;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Support\Str;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SalesOrderAgreementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
        app(SettingsService::class)->set('company.vat_status', 'Non-VAT', 'company');
        Storage::fake('local');
    }

    public function test_internal_publication_returns_private_file_metadata_without_path_and_portal_reads_own_terms(): void
    {
        [$order, $actor] = $this->orderWithActor('crm.sales_orders.update', 'crm.sales_orders.view', 'crm.sales_orders.confirm');
        $portal = $this->portal($order->customer_id);
        $response = $this->actingAs($actor)->post("/api/v1/crm/sales-orders/{$order->hash_id}/agreements", [
            'terms' => ' Net 45 days. ', 'file' => UploadedFile::fake()->create('terms.pdf', 12, 'application/pdf'),
        ]);
        $response->assertCreated()->assertJsonPath('data.revisions.0.terms', 'Net 45 days.');
        $this->assertArrayNotHasKey('file_path', $response->json('data.revisions.0'));
        $this->assertSame('terms.pdf', $response->json('data.revisions.0.file_name'));
        Storage::disk('local')->assertExists(SalesOrderAgreement::firstOrFail()->file_path);
        $this->post("/api/v1/crm/sales-orders/{$order->hash_id}/agreements", ['terms' => " \n"])->assertUnprocessable();
        $this->post("/api/v1/crm/sales-orders/{$order->hash_id}/agreements", ['terms' => 'bad file', 'file' => UploadedFile::fake()->create('bad.exe', 2)])->assertUnprocessable();
        $this->post("/api/v1/crm/sales-orders/{$order->hash_id}/agreements", ['terms' => 'large file', 'file' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')])->assertUnprocessable();
        $this->assertSame(1, SalesOrderAgreement::count());

        $portalResponse = $this->actingAs($portal, 'customer_portal')->getJson("/api/v1/b2b/customer/orders/{$order->hash_id}/agreements");
        $portalResponse->assertOk()->assertJsonPath('data.revisions.0.terms', 'Net 45 days.')
            ->assertJsonPath('data.can_acknowledge', true)->assertJsonPath('data.can_publish', false);
        $this->actingAs($actor, 'sanctum')->getJson("/api/v1/crm/sales-orders/{$order->hash_id}/agreements")
            ->assertOk()->assertJsonPath('data.can_acknowledge', false);
        $this->getJson("/api/v1/b2b/customer/orders/{$order->hash_id}/agreements/".SalesOrderAgreement::firstOrFail()->hash_id.'/file')->assertOk();
        $otherOrder = SalesOrder::factory()->create(['created_by' => $actor->id]);
        $this->actingAs($actor)->getJson("/api/v1/crm/sales-orders/{$otherOrder->hash_id}/agreements/".SalesOrderAgreement::firstOrFail()->hash_id.'/file')->assertNotFound();
        $this->postJson("/api/v1/crm/sales-orders/{$otherOrder->hash_id}/agreements/".SalesOrderAgreement::firstOrFail()->hash_id.'/acknowledge', [
            'representative' => 'Buyer', 'acknowledged_at' => now()->toIso8601String(), 'reference' => 'Email 10',
        ])->assertNotFound();
        $this->actingAs($this->portal(Customer::factory()->create()->id), 'customer_portal')
            ->getJson("/api/v1/b2b/customer/orders/{$order->hash_id}/agreements")->assertForbidden();
        $this->getJson("/api/v1/b2b/customer/orders/{$order->hash_id}/agreements/".SalesOrderAgreement::firstOrFail()->hash_id.'/file')->assertNotFound();
    }

    public function test_portal_acknowledgment_is_idempotent_and_stores_actual_customer_actor(): void
    {
        [$order, $actor] = $this->orderWithActor('crm.sales_orders.update');
        $revision = app(SalesOrderAgreementService::class)->publish($order, $actor, 'No additional terms.', null);
        $portal = $this->portal($order->customer_id);
        $this->actingAs($portal, 'customer_portal');
        $url = "/api/v1/b2b/customer/orders/{$order->hash_id}/agreements/{$revision->hash_id}/acknowledge";
        $this->actingAs($this->portal(Customer::factory()->create()->id), 'customer_portal')->postJson($url, ['acknowledged' => true])->assertForbidden();
        $this->actingAs($portal, 'customer_portal');
        $this->postJson($url, ['acknowledged' => true])->assertOk();
        $firstTime = $revision->fresh()->acknowledged_at->toIso8601String();
        $this->travel(2)->seconds();
        $this->postJson($url, ['acknowledged' => true])->assertOk();
        $this->assertSame('portal', $revision->fresh()->acknowledgment_method);
        $this->assertSame($portal->id, $revision->fresh()->portal_user_id);
        $this->assertSame($firstTime, $revision->fresh()->acknowledged_at->toIso8601String());
    }

    public function test_external_acknowledgment_accepts_past_effective_time_and_rejects_future_time(): void
    {
        [$order, $actor] = $this->orderWithActor('crm.sales_orders.update', 'crm.sales_orders.confirm');
        $revision = app(SalesOrderAgreementService::class)->publish($order, $actor, 'Customer approved by email.', null);
        $url = "/api/v1/crm/sales-orders/{$order->hash_id}/agreements/{$revision->hash_id}/acknowledge";
        $body = ['representative' => 'A. Buyer', 'acknowledged_at' => now()->subWeek()->toIso8601String(), 'reference' => 'Email thread 481'];
        $this->actingAs($actor)->postJson($url, $body)->assertOk();
        $this->assertSame('external', $revision->fresh()->acknowledgment_method);
        $this->assertSame($actor->id, $revision->fresh()->recorded_by);
        $this->assertSame('Email thread 481', $revision->fresh()->reference);
        app(SalesOrderAgreementService::class)->assertConfirmable($order->fresh());

        $second = app(SalesOrderAgreementService::class)->publish($order, $actor, 'Revised terms.', null);
        $this->postJson("/api/v1/crm/sales-orders/{$order->hash_id}/agreements/{$second->hash_id}/acknowledge", array_replace($body, ['acknowledged_at' => now()->addDay()->toIso8601String()]))->assertUnprocessable();
    }

    public function test_stale_terms_cannot_be_acknowledged_or_confirmed_and_history_cannot_be_edited(): void
    {
        [$order, $actor] = $this->orderWithActor('crm.sales_orders.update');
        $revision = app(SalesOrderAgreementService::class)->publish($order, $actor, 'Terms v1', null);
        app(SalesOrderAgreementService::class)->acknowledgeExternal($order, $revision, $actor, [
            'representative' => 'A. Buyer', 'acknowledged_at' => now()->subDay(), 'reference' => 'Email 28',
        ]);
        $order->items()->first()->update(['unit_price' => '12.50']);
        $order->update(['subtotal' => '12.50', 'total_amount' => '12.50']);
        try {
            app(SalesOrderAgreementService::class)->assertConfirmable($order->fresh());
            $this->fail('Changed commercial values must make the revision stale.');
        } catch (BusinessRuleException) {
            $this->assertSame('external', $revision->fresh()->acknowledgment_method);
        }
        try {
            Mail::fake();
            app(SalesOrderService::class)->confirm($order->fresh());
            $this->fail('The sales-order confirmation flow must enforce the stale agreement guard.');
        } catch (BusinessRuleException) {
            $this->assertSame('draft', $order->fresh()->status->value);
        }
        $portal = $this->portal($order->customer_id);
        $this->actingAs($portal, 'customer_portal')->postJson("/api/v1/b2b/customer/orders/{$order->hash_id}/agreements/{$revision->hash_id}/acknowledge", ['acknowledged' => true])->assertUnprocessable();
        $second = app(SalesOrderAgreementService::class)->publish($order->fresh(), $actor, 'Terms v2', null);
        $this->postJson("/api/v1/b2b/customer/orders/{$order->hash_id}/agreements/{$revision->hash_id}/acknowledge", ['acknowledged' => true])->assertUnprocessable();
        $this->postJson("/api/v1/b2b/customer/orders/{$order->hash_id}/agreements/{$second->hash_id}/acknowledge", ['acknowledged' => true])->assertOk();
        $this->actingAs($actor);
        app(SalesOrderService::class)->confirm($order->fresh());
        $this->assertSame('confirmed', $order->fresh()->status->value);
        $summary = app(SalesOrderAgreementService::class)->summary($order->fresh(), request(), true);
        $this->assertTrue($summary['revisions'][1]['is_stale']);
        $this->assertFalse($summary['revisions'][0]['is_stale']);
        try {
            $revision->update(['terms' => 'rewritten']);
            $this->fail('Published terms must be immutable.');
        } catch (\LogicException) {
            $this->assertSame('Terms v1', $revision->fresh()->terms);
        }
    }

    public function test_no_agreement_keeps_confirmation_guard_open_and_product_renaming_does_not_stale_terms(): void
    {
        [$order, $actor, $product] = $this->orderWithActor('crm.sales_orders.update');
        app(SalesOrderAgreementService::class)->assertConfirmable($order);
        $revision = app(SalesOrderAgreementService::class)->publish($order, $actor, 'Terms', null);
        $product->update(['name' => 'Renamed catalogue label']);
        $this->assertFalse(app(SalesOrderAgreementService::class)->summary($order->fresh(), request(), true)['revisions'][0]['is_stale']);
        $this->assertSame(1, $revision->revision);
    }

    public function test_published_terms_block_customer_reassignment_and_order_deletion(): void
    {
        [$order, $actor] = $this->orderWithActor('crm.sales_orders.update');
        app(SalesOrderAgreementService::class)->publish($order, $actor, 'Terms', null);
        try {
            app(\App\Modules\CRM\Services\SalesOrderService::class)->update($order, ['customer_id' => Customer::factory()->create()->id, 'items' => []]);
            $this->fail('Published terms must remain owned by the original customer.');
        } catch (BusinessRuleException) {
            $this->assertSame($order->customer_id, $order->fresh()->customer_id);
        }
        try {
            app(\App\Modules\CRM\Services\SalesOrderService::class)->delete($order);
            $this->fail('Published terms must not be orphaned by order deletion.');
        } catch (BusinessRuleException) {
            $this->assertFalse($order->fresh()->trashed());
        }
    }

    public function test_customer_return_detail_projects_original_order_terms_read_only_without_changing_case(): void
    {
        [$order, $actor] = $this->orderWithActor('crm.sales_orders.update');
        $agreement = app(SalesOrderAgreementService::class)->publish($order, $actor, 'Terms captured at shipment.', UploadedFile::fake()->create('proof.pdf', 4, 'application/pdf'));
        $delivery = Delivery::create(['delivery_number' => 'DEL-AGREE-'.Str::upper(Str::random(8)), 'sales_order_id' => $order->id,
            'status' => 'confirmed', 'scheduled_date' => today()->toDateString(), 'delivered_at' => now(), 'created_by' => $actor->id]);
        $portal = $this->portal($order->customer_id);
        $case = ReturnCase::forceCreate(['case_number' => 'RMA-AGREE-'.Str::upper(Str::random(8)), 'type' => 'customer', 'status' => 'submitted',
            'customer_id' => $order->customer_id, 'delivery_id' => $delivery->id, 'created_by' => $actor->id,
            'customer_portal_user_id' => $portal->id, 'preferred_resolution' => 'advice', 'description' => 'Review order terms']);
        $this->actingAs($portal, 'customer_portal')->getJson('/api/v1/b2b/customer/problems/'.$case->hash_id)
            ->assertOk()->assertJsonPath('data.order_agreements.revisions.0.terms', 'Terms captured at shipment.')
            ->assertJsonPath('data.order_agreements.can_publish', false)
            ->assertJsonPath('data.order_agreements.can_record_external', false)
            ->assertJsonPath('data.order_agreements.can_acknowledge', false);
        $reviewer = $this->userWithPermission('return_management.view');
        $this->actingAs($reviewer, 'sanctum')->getJson('/api/v1/return-management/cases/'.$case->hash_id)
            ->assertOk()->assertJsonPath('data.order_agreements.revisions.0.terms', 'Terms captured at shipment.');
        $this->get("/api/v1/crm/sales-orders/{$order->hash_id}/agreements/{$agreement->hash_id}/file")->assertOk();
        $unlinked = SalesOrder::factory()->create(['created_by' => $actor->id]);
        $unlinkedAgreement = app(SalesOrderAgreementService::class)->publish($unlinked, $actor, 'Unlinked terms', UploadedFile::fake()->create('unlinked.pdf', 2, 'application/pdf'));
        $this->get("/api/v1/crm/sales-orders/{$unlinked->hash_id}/agreements/{$unlinkedAgreement->hash_id}/file")->assertNotFound();
        $this->assertSame('submitted', $case->fresh()->status->value);
    }

    private function orderWithActor(string ...$permissions): array
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $role = Role::create(['name' => 'Agreement '.uniqid(), 'slug' => 'agreement_'.uniqid()]);
        foreach ($permissions as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'crm']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $actor = User::factory()->create(['role_id' => $role->id]);
        $order = SalesOrder::factory()->create(['customer_id' => $customer->id, 'created_by' => $actor->id, 'subtotal' => '2.00', 'vat_amount' => '0.00', 'total_amount' => '2.00']);
        $order->items()->create(['product_id' => $product->id, 'quantity' => '1.00', 'unit_price' => '2.00', 'total' => '2.00', 'delivery_date' => now()->addDays(10)->toDateString()]);
        return [$order, $actor, $product];
    }

    private function portal(int $customerId): CustomerPortalUser
    {
        return CustomerPortalUser::create(['customer_id' => $customerId, 'name' => 'Buyer '.uniqid(), 'email' => uniqid().'@example.test', 'password' => bcrypt('Password1!'), 'is_active' => true]);
    }

    private function userWithPermission(string $slug): User
    {
        $role = Role::create(['name' => 'Return view '.uniqid(), 'slug' => 'return_view_'.uniqid()]);
        $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'return_management']);
        $role->permissions()->attach($permission->id);
        return User::factory()->create(['role_id' => $role->id]);
    }
}
