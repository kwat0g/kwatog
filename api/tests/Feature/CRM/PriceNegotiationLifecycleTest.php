<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\NotificationSubjectAccessRegistry;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\PriceRequestStatus;
use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\PriceAgreementService;
use App\Modules\CRM\Services\PriceNegotiationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PriceNegotiationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actor(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $role)->value('id')]);
    }

    private function product(): Product
    {
        return Product::factory()->create(['standard_cost' => '10.00', 'minimum_selling_price' => '15.00', 'base_selling_price' => '50.00', 'portal_visibility' => 'standard']);
    }

    private function portal(): CustomerPortalUser
    {
        return CustomerPortalUser::create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Buyer', 'email' => uniqid().'@test.local', 'password' => 'password', 'is_active' => true, 'password_changed_at' => now()]);
    }

    public function test_customer_request_finance_review_and_acceptance_apply_terms_only_once(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $request = $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests', [
            'product_id' => $product->hash_id, 'quantity' => 100, 'requested_price' => '30.00', 'notes' => 'Order volume discount',
        ])->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');
        $this->actingAs($this->actor('sales_officer'), 'web')->putJson('/api/v1/crm/price-requests/'.$request.'/offer', [
            'price' => '50.00', 'pricing_method' => 'tiered', 'tiers' => [['min_qty' => 100, 'unit_price' => '30.00']],
            'effective_from' => today()->toDateString(), 'effective_to' => today()->addMonth()->toDateString(), 'notes' => 'Discount for 100 pieces or more',
        ])->assertOk()->assertJsonPath('data.status', 'pending_finance');
        $this->assertSame('50.00', app(PriceAgreementService::class)->quote($buyer->customer_id, $product->id, today(), 100)['unit_price']);
        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests/'.$request.'/respond', ['decision' => 'accept', 'version' => 1])->assertUnprocessable();
        auth()->forgetGuards();
        $this->actingAs($this->actor('finance_officer'), 'web')->postJson('/api/v1/crm/price-requests/'.$request.'/approve', ['notes' => 'Reviewed standard cost and floor', 'version' => 1, 'cost_signature' => app(PriceNegotiationService::class)->reviewCost($product)['signature']])->assertOk()->assertJsonPath('data.status', 'offered');
        $this->getJson('/api/v1/crm/price-requests/'.$request)->assertOk()->assertJsonPath('data.cost_review.cost', '10.00')->assertJsonPath('data.cost_review.incomplete', false);
        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests/'.$request.'/respond', ['decision' => 'accept', 'version' => 1])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->getJson('/api/v1/b2b/customer/price-requests')->assertOk()->assertJsonMissingPath('data.0.cost_review')->assertJsonMissingPath('data.0.offer.agreement_signature');
        $this->assertSame('30.00', app(PriceAgreementService::class)->quote($buyer->customer_id, $product->id, today(), 100)['unit_price']);
        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests/'.$request.'/respond', ['decision' => 'accept', 'version' => 1])->assertOk();
        $this->assertDatabaseCount('product_price_agreements', 1);
    }

    public function test_other_customer_cannot_read_or_accept_request(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $request = $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests', ['product_id' => $product->hash_id, 'quantity' => 100, 'notes' => 'Discount request'])->assertCreated()->json('data.id');
        $product->delete();
        $this->getJson('/api/v1/b2b/customer/price-requests')->assertOk()->assertJsonPath('data.0.id', $request);
        $this->actingAs($this->portal(), 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests/'.$request.'/respond', ['decision' => 'accept', 'version' => 1])->assertNotFound();
        $this->getJson('/api/v1/b2b/customer/price-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_accepted_agreement_cannot_be_silently_edited_or_archived(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $agreement = app(PriceAgreementService::class)->create(['customer_id' => $buyer->customer_id, 'product_id' => $product->id, 'price' => '30.00', 'effective_from' => today()->toDateString(), 'effective_to' => today()->addMonth()->toDateString()]);
        $this->actingAs($this->actor('system_admin'), 'web')->putJson('/api/v1/crm/price-agreements/'.$agreement->hash_id, ['price' => '35.00'])->assertUnprocessable();
        $this->deleteJson('/api/v1/crm/price-agreements/'.$agreement->hash_id)->assertUnprocessable();
        $this->assertSame('30.00', $agreement->fresh()->price);
    }

    public function test_offer_version_and_cost_change_block_stale_acceptance(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $sales = $this->actor('sales_officer');
        $finance = $this->actor('finance_officer');
        $service = app(PriceNegotiationService::class);
        $request = $service->request($buyer, ['product_id' => $product->id, 'quantity' => 100, 'notes' => 'Volume request']);
        $terms = ['price' => '30.00', 'pricing_method' => 'flat', 'tiers' => null, 'effective_from' => today()->toDateString(), 'effective_to' => today()->addMonth()->toDateString(), 'notes' => 'Reviewed discount'];
        $service->offer($request, $sales, $terms);
        $service->offer($request, $sales, $terms);
        $service->approve($request, $finance, ['notes' => 'Reviewed', 'version' => $request->fresh()->version, 'cost_signature' => $service->reviewCost($product)['signature']]);
        try {
            $service->respond($request, $buyer, 'accept', 1);
            $this->fail('Stale version must be refused.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Offer changed', $error->getMessage());
        }
        $product->update(['standard_cost' => '20.00']);
        try {
            $service->respond($request, $buyer, 'accept', 2);
            $this->fail('Cost change must require review.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Cost or price limits changed', $error->getMessage());
        }
        $this->assertDatabaseCount('product_price_agreements', 0);
    }

    public function test_replacement_applies_only_from_accepted_effective_date(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $old = app(PriceAgreementService::class)->create(['product_id' => $product->id, 'customer_id' => $buyer->customer_id, 'price' => '35.00', 'effective_from' => today()->subMonth()->toDateString(), 'effective_to' => today()->addMonth()->toDateString()]);
        $service = app(PriceNegotiationService::class);
        $request = $service->offer(null, $this->actor('sales_officer'), ['product_id' => $product->id, 'customer_id' => $buyer->customer_id, 'price' => '30.00', 'pricing_method' => 'flat', 'effective_from' => today()->addWeek()->toDateString(), 'effective_to' => today()->addMonths(2)->toDateString(), 'notes' => 'New terms']);
        $service->approve($request, $this->actor('finance_officer'), ['notes' => 'Reviewed', 'version' => $request->fresh()->version, 'cost_signature' => $service->reviewCost($product)['signature']]);
        $service->respond($request, $buyer, 'accept', 1);
        $this->assertSame('35.00', app(PriceAgreementService::class)->quote($buyer->customer_id, $product->id, today(), 100)['unit_price']);
        $this->assertSame('30.00', app(PriceAgreementService::class)->quote($buyer->customer_id, $product->id, today()->addWeek(), 100)['unit_price']);
        $this->assertSame('35.00', $old->fresh()->price);
    }

    public function test_internal_notice_excludes_actor_and_opens_the_exact_request(): void
    {
        app(SettingsService::class)->set('modules.crm', true, 'modules');
        $buyer = $this->portal();
        $product = $this->product();
        $sales = $this->actor('sales_officer');
        $peer = $this->actor('sales_officer');
        $outsider = $this->actor('warehouse_staff');
        $request = app(PriceNegotiationService::class)->request($buyer, ['product_id' => $product->id, 'quantity' => 100, 'notes' => 'Volume discount']);
        $this->assertTrue($sales->hasPermission('crm.price_agreements.propose'));
        $this->assertTrue(NotificationSubjectAccessRegistry::supports($request->getMorphClass()));
        $this->assertTrue(NotificationSubjectAccessRegistry::userCanViewRecord($peer, $request, '/crm/price-agreements/requests?request='.$request->hash_id));
        $actorCount = $sales->notifications()->where('type', 'pricing.negotiation')->count();

        app(PriceNegotiationService::class)->withdraw($request, $sales, 'Customer changed volume');

        $dedupeKey = 'pricing:negotiation:'.$request->id.':'.($request->fresh()->version ?? 0).':withdrawn';
        $notice = $peer->notifications()->where('dedupe_key', $dedupeKey)->first();
        $this->assertNotNull($notice, json_encode(DB::table('notifications')->where('notifiable_id', $peer->id)->where('type', 'pricing.negotiation')->pluck('dedupe_key')->all()));
        $this->assertSame($actorCount, $sales->notifications()->where('type', 'pricing.negotiation')->count());
        $this->assertSame(0, $outsider->notifications()->where('dedupe_key', $dedupeKey)->count());
        $this->assertSame('/crm/price-agreements/requests?request='.$request->hash_id, $notice->data['link_to']);
        $this->assertSame(PriceRequest::class, $notice->data['subject_type']);
        $this->assertSame((string) $request->id, $notice->data['subject_id']);

        $this->actingAs($peer)->getJson('/api/v1/crm/price-requests?request='.$request->hash_id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $request->hash_id);
        $this->actingAs($peer)->getJson('/api/v1/crm/price-requests?request=invalid')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_portal_notice_is_exact_and_stays_inside_the_customer_account(): void
    {
        app(SettingsService::class)->set('modules.crm', true, 'modules');
        $buyer = $this->portal();
        $sameCustomer = CustomerPortalUser::create(['customer_id' => $buyer->customer_id, 'name' => 'Second buyer', 'email' => uniqid().'@test.local', 'password' => 'password', 'is_active' => true, 'password_changed_at' => now()]);
        $otherCustomer = $this->portal();
        $product = $this->product();
        $request = PriceRequest::create([
            'customer_id' => $buyer->customer_id, 'product_id' => $product->id, 'requested_by' => $buyer->id,
            'quantity' => 100, 'requested_price' => '30.00', 'notes' => 'Volume discount',
            'status' => PriceRequestStatus::Offered, 'offer' => ['price' => '50.00'], 'version' => 1,
        ]);

        app(PriceNegotiationService::class)->respond($request, $buyer, 'reject', 1);

        $dedupeKey = 'pricing:'.$request->id.':1:rejected';
        $notice = DB::table('notifications')->where('notifiable_type', CustomerPortalUser::class)
            ->where('notifiable_id', $sameCustomer->id)->where('dedupe_key', $dedupeKey)->first();
        $this->assertNotNull($notice);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_type', CustomerPortalUser::class)->where('notifiable_id', $buyer->id)->where('dedupe_key', $dedupeKey)->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_type', CustomerPortalUser::class)->where('notifiable_id', $otherCustomer->id)->where('dedupe_key', $dedupeKey)->count());
        $this->assertSame('/portal/customer/pricing?request='.$request->hash_id, json_decode($notice->data, true)['link_to']);

        $this->actingAs($sameCustomer, 'customer_portal')->getJson('/api/v1/b2b/customer/price-requests?request='.$request->hash_id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $request->hash_id);
        $this->actingAs($otherCustomer, 'customer_portal')->getJson('/api/v1/b2b/customer/price-requests?request='.$request->hash_id)
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
