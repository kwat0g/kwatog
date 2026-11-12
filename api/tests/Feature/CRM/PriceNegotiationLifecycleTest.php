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
use App\Modules\CRM\Mail\PriceAgreementTerminationNoticeMail;
use App\Modules\CRM\Models\CustomerAgreementDocument;
use App\Modules\CRM\Enums\PriceRequestStatus;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\PriceAgreementService;
use App\Modules\CRM\Services\PriceNegotiationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_customer_can_cancel_own_request_and_sales_can_reject_but_not_withdraw_it(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $sales = $this->actor('sales_officer');
        $requestId = $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests', [
            'product_id' => $product->hash_id,
            'quantity' => 100,
            'requested_price' => '30.00',
            'notes' => 'Please review volume pricing.',
        ])->assertCreated()
            ->assertJsonPath('data.available_actions.can_cancel', true)
            ->json('data.id');

        $this->actingAs($sales, 'web')->getJson('/api/v1/crm/price-requests')
            ->assertOk()
            ->assertJsonPath('data.0.available_actions.can_reject', true)
            ->assertJsonPath('data.0.available_actions.can_withdraw', false);

        $this->postJson('/api/v1/crm/price-requests/'.$requestId.'/withdraw', [
            'notes' => 'Customer changed their plan.',
            'version' => 1,
        ])->assertForbidden();

        $this->postJson('/api/v1/crm/price-requests/'.$requestId.'/reject', [
            'notes' => 'Requested price is below the approved floor.',
            'version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'sales_rejected');

        $this->actingAs($buyer, 'customer_portal')->getJson('/api/v1/b2b/customer/price-requests')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'sales_rejected')
            ->assertJsonPath('data.0.available_actions.can_cancel', false);
    }

    public function test_customer_cancellation_is_tenant_scoped_and_crm_can_withdraw_its_own_proposal(): void
    {
        $buyer = $this->portal();
        $otherBuyer = $this->portal();
        $product = $this->product();
        $requestId = $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests', [
            'product_id' => $product->hash_id,
            'quantity' => 100,
            'requested_price' => '30.00',
            'notes' => 'Please review volume pricing.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($otherBuyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests/'.$requestId.'/cancel', [
            'version' => 1,
        ])->assertNotFound();

        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests/'.$requestId.'/cancel', [
            'version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'customer_cancelled');

        $sales = $this->actor('sales_officer');
        $customer = Customer::factory()->create();
        $companyProposalId = $this->actingAs($sales, 'web')->postJson('/api/v1/crm/price-requests', [
            'customer_id' => $customer->hash_id,
            'product_id' => $product->hash_id,
            'price' => '50.00',
            'pricing_method' => 'flat',
            'effective_from' => today()->toDateString(),
            'effective_to' => today()->addMonth()->toDateString(),
            'notes' => 'Company pricing proposal.',
        ])->assertCreated()
            ->assertJsonPath('data.available_actions.can_withdraw', true)
            ->assertJsonPath('data.available_actions.can_reject', false)
            ->json('data.id');

        $this->postJson('/api/v1/crm/price-requests/'.$companyProposalId.'/reject', [
            'notes' => 'This is not a customer-submitted request.',
            'version' => 1,
        ])->assertForbidden();

        $this->postJson('/api/v1/crm/price-requests/'.$companyProposalId.'/withdraw', [
            'notes' => 'Replacing this proposal with revised terms.',
            'version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'withdrawn');
    }

    public function test_customer_cannot_request_pricing_outside_their_per_order_quantity_limits(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        DB::table('customer_product_access')->insert([
            'customer_id' => $buyer->customer_id,
            'product_id' => $product->id,
            'minimum_order_quantity' => '5.000',
            'maximum_order_quantity' => '20.000',
        ]);

        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests', [
            'product_id' => $product->hash_id,
            'quantity' => 21,
            'requested_price' => '24.00',
            'notes' => 'Request volume pricing for a planned order.',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertDatabaseMissing('price_requests', [
            'customer_id' => $buyer->customer_id,
            'product_id' => $product->id,
        ]);
    }

    public function test_customer_target_price_is_required(): void
    {
        $buyer = $this->portal();
        $product = $this->product();

        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests', [
            'product_id' => $product->hash_id,
            'quantity' => 100,
            'notes' => 'Please review pricing for the planned order.',
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_price');

        $this->assertDatabaseMissing('price_requests', [
            'customer_id' => $buyer->customer_id,
            'product_id' => $product->id,
        ]);
    }

    public function test_customer_can_request_a_limit_review_without_changing_the_current_limit(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        DB::table('customer_product_access')->insert([
            'customer_id' => $buyer->customer_id,
            'product_id' => $product->id,
            'minimum_order_quantity' => '5.000',
            'maximum_order_quantity' => '20.000',
        ]);

        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests', [
            'product_id' => $product->hash_id,
            'quantity' => 25,
            'requested_price' => '24.00',
            'request_order_limit_increase' => true,
            'notes' => 'Planned quarterly replenishment.',
        ])->assertCreated()
            ->assertJsonPath('data.order_limit_increase_request.current_maximum_quantity', '20.000')
            ->assertJsonPath('data.order_limit_increase_request.requested_quantity', '25');

        $this->assertSame('20.000', DB::table('customer_product_access')
            ->where('customer_id', $buyer->customer_id)->where('product_id', $product->id)
            ->value('maximum_order_quantity'));
    }

    public function test_open_request_lookup_is_limited_to_the_signed_in_customer(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $request = $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests', [
            'product_id' => $product->hash_id,
            'quantity' => 100,
            'requested_price' => '30.00',
            'notes' => 'Discount request',
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/v1/b2b/customer/price-requests/open')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $request);

        auth()->forgetGuards();
        $this->actingAs($this->portal(), 'customer_portal')->getJson('/api/v1/b2b/customer/price-requests/open')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_other_customer_cannot_read_or_accept_request(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $request = $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/price-requests', ['product_id' => $product->hash_id, 'quantity' => 100, 'requested_price' => '30.00', 'notes' => 'Discount request'])->assertCreated()->json('data.id');
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

    public function test_replacement_proposal_uses_the_agreement_parties_and_rejects_party_overrides(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $agreement = app(PriceAgreementService::class)->create([
            'product_id' => $product->id,
            'customer_id' => $buyer->customer_id,
            'price' => '35.00',
            'effective_from' => today()->subMonth()->toDateString(),
            'effective_to' => today()->addMonth()->toDateString(),
        ]);
        $terms = [
            'price' => '34.00',
            'pricing_method' => 'tiered',
            'tiers' => [['min_qty' => 100, 'unit_price' => '34.00']],
            'effective_from' => today()->addMonth()->toDateString(),
            'effective_to' => today()->addMonths(2)->toDateString(),
            'notes' => 'Revised price for 100 pieces or more.',
        ];
        $sales = $this->actor('sales_officer');

        $response = $this->actingAs($sales, 'web')->postJson(
            '/api/v1/crm/price-agreements/'.$agreement->hash_id.'/replacement-proposals',
            $terms,
        );

        $response->assertCreated()
            ->assertJsonPath('data.customer.id', $buyer->customer->hash_id)
            ->assertJsonPath('data.product.id', $product->hash_id);
        $this->assertDatabaseHas('price_requests', [
            'customer_id' => $buyer->customer_id,
            'product_id' => $product->id,
        ]);

        $otherCustomer = Customer::factory()->create();
        $otherProduct = $this->product();
        $this->postJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/replacement-proposals', [
            ...$terms,
            'customer_id' => $otherCustomer->hash_id,
            'product_id' => $otherProduct->hash_id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'product_id']);

        $this->assertDatabaseCount('price_requests', 1);
    }

    public function test_replacement_proposal_rejects_invalid_price_dates_and_quantity_tiers(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $agreement = app(PriceAgreementService::class)->create([
            'product_id' => $product->id,
            'customer_id' => $buyer->customer_id,
            'price' => '35.00',
            'effective_from' => today()->subMonth()->toDateString(),
            'effective_to' => today()->addMonth()->toDateString(),
        ]);

        $this->actingAs($this->actor('sales_officer'), 'web')
            ->postJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/replacement-proposals', [
                'price' => '1e9',
                'pricing_method' => 'unlisted',
                'tiers' => [['min_qty' => 0, 'unit_price' => '-1']],
                'effective_from' => 'not-a-date',
                'effective_to' => '2026-02-31',
                'notes' => str_repeat('x', 1001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'price',
                'pricing_method',
                'tiers.0.min_qty',
                'tiers.0.unit_price',
                'effective_from',
                'effective_to',
                'notes',
            ]);

        $this->assertDatabaseCount('price_requests', 0);
    }

    public function test_replacement_proposal_must_start_after_overlapping_current_or_scheduled_agreements(): void
    {
        $buyer = $this->portal();
        $product = $this->product();
        $agreement = app(PriceAgreementService::class)->create([
            'product_id' => $product->id,
            'customer_id' => $buyer->customer_id,
            'price' => '35.00',
            'effective_from' => today()->toDateString(),
            'effective_to' => today()->addYear()->toDateString(),
        ]);

        $this->actingAs($this->actor('sales_officer'), 'web')
            ->postJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/replacement-proposals', [
                'price' => '35.00',
                'pricing_method' => 'flat',
                'tiers' => null,
                'effective_from' => today()->toDateString(),
                'effective_to' => today()->addYear()->toDateString(),
                'notes' => 'Updated replacement terms.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('effective_from');

        $this->assertDatabaseCount('price_requests', 0);
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

    public function test_manager_requests_customer_signature_before_an_active_agreement_changes(): void
    {
        app(SettingsService::class)->set('modules.crm', true, 'modules');
        Storage::fake('local');
        Mail::fake();
        $customer = Customer::factory()->create();
        $buyer = CustomerPortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Buyer',
            'email' => uniqid().'@test.local',
            'password' => 'password',
            'is_active' => true,
            'is_authorized_signatory' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
        $product = $this->product();
        $manager = $this->actor('system_admin');
        $agreement = app(PriceAgreementService::class)->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => '30.00',
            'effective_from' => today()->subMonth()->toDateString(),
            'effective_to' => today()->addMonth()->toDateString(),
        ]);
        $lastValidDate = today()->toDateString();

        $this->actingAs($manager, 'web')->postJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/termination-requests', [
            'last_valid_date' => $lastValidDate,
            'reason' => 'Customer requested termination in a signed amendment.',
        ])->assertOk()
            ->assertJsonPath('data.termination_status', 'awaiting_customer_signature')
            ->assertJsonPath('data.termination_last_valid_date', $lastValidDate);

        $document = CustomerAgreementDocument::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('pending', $document->signature_status);
        $this->assertSame('Price agreement termination - '.$product->part_number.' - '.$lastValidDate.'.pdf', $document->filename);
        $this->assertSame(today()->addMonth()->toDateString(), $agreement->fresh()->effective_to->toDateString());
        $this->assertSame('30.00', $agreement->fresh()->price);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->id,
            'model_type' => PriceAgreement::class,
            'model_id' => $agreement->id,
            'action' => 'term_requested',
            'reason' => 'Customer requested termination in a signed amendment.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => CustomerPortalUser::class,
            'notifiable_id' => $buyer->id,
            'type' => 'pricing.agreement_termination',
            'dedupe_key' => 'pricing:agreement-termination:'.$agreement->id.':'.$document->id.':signature_request',
        ]);
        Mail::assertQueued(PriceAgreementTerminationNoticeMail::class);

        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/agreement-documents/'.$document->hash_id.'/sign', [
            'typed_name' => 'Buyer Authorized Signatory',
        ])->assertOk()->assertJsonPath('data.signature_status', 'signed');

        $this->assertSame($lastValidDate, $agreement->fresh()->effective_to->toDateString());
        $this->assertSame('30.00', $agreement->fresh()->price);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'customer_portal',
            'model_type' => PriceAgreement::class,
            'model_id' => $agreement->id,
            'action' => 'term_signed',
        ]);

        $this->actingAs($manager, 'web')->getJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/termination-history')
            ->assertOk()
            ->assertJsonPath('data.entries.0.status', 'ended_early')
            ->assertJsonPath('data.entries.0.actor_type', 'customer_portal')
            ->assertJsonPath('data.entries.0.signed_name', 'Buyer Authorized Signatory')
            ->assertJsonPath('data.entries.1.status', 'awaiting_customer_signature');
    }

    public function test_customer_signature_is_rejected_when_price_coverage_changes_after_request(): void
    {
        Storage::fake('local');
        Mail::fake();
        $customer = Customer::factory()->create();
        $buyer = CustomerPortalUser::create([
            'customer_id' => $customer->id, 'name' => 'Buyer', 'email' => uniqid().'@test.local',
            'password' => 'password', 'is_active' => true, 'is_authorized_signatory' => true,
            'must_change_password' => false, 'password_changed_at' => now(),
        ]);
        $product = $this->product();
        $manager = $this->actor('system_admin');
        $agreement = app(PriceAgreementService::class)->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => '30.00',
            'effective_from' => today()->subMonth()->toDateString(),
            'effective_to' => today()->addMonth()->toDateString(),
        ]);
        $this->actingAs($manager, 'web')->postJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/termination-requests', [
            'last_valid_date' => today()->toDateString(),
            'reason' => 'Customer requested termination in a signed amendment.',
        ])->assertOk();
        $document = CustomerAgreementDocument::query()->where('customer_id', $customer->id)->firstOrFail();
        $product->update(['base_selling_price' => null]);

        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/agreement-documents/'.$document->hash_id.'/sign', [
            'typed_name' => 'Buyer Authorized Signatory',
        ])->assertUnprocessable()->assertJsonPath('message', 'Price availability changed while the document was waiting. Ask your account manager to send an updated request.');

        $this->assertSame('pending', $document->fresh()->signature_status);
        $this->assertSame(today()->addMonth()->toDateString(), $agreement->fresh()->effective_to->toDateString());
        $this->assertDatabaseMissing('audit_logs', [
            'model_type' => PriceAgreement::class,
            'model_id' => $agreement->id,
            'action' => 'term_signed',
        ]);
    }

    public function test_scheduled_agreement_is_only_cancelled_after_customer_signature(): void
    {
        Storage::fake('local');
        Mail::fake();
        $customer = Customer::factory()->create();
        $buyer = CustomerPortalUser::create([
            'customer_id' => $customer->id, 'name' => 'Buyer', 'email' => uniqid().'@test.local',
            'password' => 'password', 'is_active' => true, 'is_authorized_signatory' => true,
            'must_change_password' => false, 'password_changed_at' => now(),
        ]);
        $product = $this->product();
        $agreement = app(PriceAgreementService::class)->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => '30.00',
            'effective_from' => today()->addMonth()->toDateString(),
            'effective_to' => today()->addMonths(2)->toDateString(),
        ]);
        $this->actingAs($this->actor('system_admin'), 'web')
            ->postJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/termination-requests', [
                'reason' => 'Customer cancelled the upcoming price arrangement.',
            ])->assertOk()->assertJsonPath('data.termination_status', 'awaiting_customer_signature');
        $document = CustomerAgreementDocument::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(today()->addMonths(2)->toDateString(), $agreement->fresh()->effective_to->toDateString());

        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/agreement-documents/'.$document->hash_id.'/sign', [
            'typed_name' => 'Buyer Authorized Signatory',
        ])->assertOk();

        $this->assertSame($agreement->fresh()->effective_from->copy()->subDay()->toDateString(), $agreement->fresh()->effective_to->toDateString());
        $this->assertSame('cancelled_before_start', app(PriceAgreementService::class)->show($agreement->fresh())->termination_status);
    }

    public function test_manager_can_cancel_a_pending_signature_request_without_changing_agreement_terms(): void
    {
        Storage::fake('local');
        Mail::fake();
        $customer = Customer::factory()->create();
        $buyer = CustomerPortalUser::create([
            'customer_id' => $customer->id, 'name' => 'Buyer', 'email' => uniqid().'@test.local',
            'password' => 'password', 'is_active' => true, 'is_authorized_signatory' => true,
            'must_change_password' => false, 'password_changed_at' => now(),
        ]);
        $product = $this->product();
        $agreement = app(PriceAgreementService::class)->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => '30.00',
            'effective_from' => today()->subMonth()->toDateString(),
            'effective_to' => today()->addMonth()->toDateString(),
        ]);
        $manager = $this->actor('system_admin');
        $this->actingAs($manager, 'web')->postJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/termination-requests', [
            'last_valid_date' => today()->toDateString(),
            'reason' => 'Customer requested an earlier agreement end.',
        ])->assertOk();
        $document = CustomerAgreementDocument::query()->where('customer_id', $customer->id)->firstOrFail();

        $this->actingAs($manager, 'web')->postJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/termination-requests/cancel', [
            'document_id' => $document->hash_id,
        ])->assertOk()->assertJsonPath('data.termination_status', 'request_cancelled');

        $this->assertSame('cancelled', $document->fresh()->signature_status);
        $this->assertSame(today()->addMonth()->toDateString(), $agreement->fresh()->effective_to->toDateString());
        $this->actingAs($buyer, 'customer_portal')->getJson('/api/v1/b2b/customer/agreement-documents')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($buyer, 'customer_portal')->postJson('/api/v1/b2b/customer/agreement-documents/'.$document->hash_id.'/sign', [
            'typed_name' => 'Buyer Authorized Signatory',
        ])->assertNotFound();
    }

    public function test_price_coverage_gap_requires_manager_acknowledgement_before_request_is_created(): void
    {
        Storage::fake('local');
        Mail::fake();
        $customer = Customer::factory()->create();
        CustomerPortalUser::create([
            'customer_id' => $customer->id, 'name' => 'Buyer', 'email' => uniqid().'@test.local',
            'password' => 'password', 'is_active' => true, 'is_authorized_signatory' => true,
            'must_change_password' => false, 'password_changed_at' => now(),
        ]);
        $product = $this->product();
        $product->update(['base_selling_price' => null]);
        $agreement = app(PriceAgreementService::class)->create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'price' => '30.00',
            'effective_from' => today()->subMonth()->toDateString(),
            'effective_to' => today()->addMonth()->toDateString(),
        ]);

        $payload = [
            'last_valid_date' => today()->toDateString(),
            'reason' => 'Customer requested an earlier agreement end.',
        ];
        $this->actingAs($this->actor('sales_officer'), 'web')
            ->postJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/termination-requests', $payload)
            ->assertForbidden();
        auth()->forgetGuards();
        $this->actingAs($this->actor('system_admin'), 'web')
            ->postJson('/api/v1/crm/price-agreements/'.$agreement->hash_id.'/termination-requests', [
                ...$payload,
            ])->assertUnprocessable()->assertJsonValidationErrors('coverage_gap_acknowledged');

        $this->assertSame(0, CustomerAgreementDocument::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(today()->addMonth()->toDateString(), $agreement->fresh()->effective_to->toDateString());
    }
}
