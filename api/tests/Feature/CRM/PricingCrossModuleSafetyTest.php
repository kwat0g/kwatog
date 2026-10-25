<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Models\SchedulerTickRun;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\PriceNegotiationService;
use App\Modules\Dashboard\Services\ActionCenterService;
use App\Modules\Dashboard\Services\RolloutHealthService;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\ItemCostReviewService;
use App\Modules\MRP\Events\BomPricingReviewRequested;
use App\Modules\MRP\Models\Bom;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PricingCrossModuleSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actor($slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
    }

    private function product(): Product
    {
        return Product::factory()->create(['standard_cost' => '10.00', 'minimum_selling_price' => '15.00', 'base_selling_price' => '50.00', 'portal_visibility' => 'standard']);
    }

    private function buyer(): CustomerPortalUser
    {
        return CustomerPortalUser::create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Audit buyer', 'email' => uniqid().'@test.local', 'password' => 'password', 'is_active' => true, 'password_changed_at' => now()]);
    }

    private function approval(Product $p, $r): array
    {
        return ['notes' => 'Reviewed', 'version' => $r->fresh()->version, 'cost_signature' => app(PriceNegotiationService::class)->reviewCost($p)['signature']];
    }

    private function terms(Product $p, CustomerPortalUser $b): array
    {
        return ['product_id' => $p->id, 'customer_id' => $b->customer_id, 'price' => '30.00', 'pricing_method' => 'flat', 'effective_from' => today()->toDateString(), 'effective_to' => today()->addMonth()->toDateString(), 'notes' => 'Audit proposal'];
    }

    public function test_stale_finance_revision_is_blocked(): void
    {
        $s = app(PriceNegotiationService::class);
        $p = $this->product();
        $b = $this->buyer();
        $sales = $this->actor('sales_officer');
        $finance = $this->actor('finance_officer');
        $r = $s->offer(null, $sales, $this->terms($p, $b));
        $this->actingAs($finance)->getJson('/api/v1/crm/price-requests/'.$r->hash_id)->assertOk()->assertJsonPath('data.version', 1);
        $s->offer($r, $sales, array_merge($this->terms($p, $b), ['price' => '16.00']));
        $this->postJson('/api/v1/crm/price-requests/'.$r->hash_id.'/approve', ['notes' => 'Reviewed earlier revision', 'version' => 1, 'cost_signature' => $s->reviewCost($p)['signature']])->assertUnprocessable();
    }

    public function test_unexpired_offer_can_be_accepted_next_day(): void
    {
        $s = app(PriceNegotiationService::class);
        $p = $this->product();
        $b = $this->buyer();
        $r = $s->offer(null, $this->actor('sales_officer'), $this->terms($p, $b));
        $s->approve($r, $this->actor('finance_officer'), $this->approval($p, $r));
        $this->travel(1)->days();
        $s->respond($r, $b, 'accept', 1);
        $this->assertSame('accepted', $r->fresh()->status->value);
        $this->travelBack();
    }

    public function test_received_finished_good_cost_blocks_finance_approval_below_cost(): void
    {
        $p = $this->product();
        $i = Item::factory()->create(['code' => $p->part_number, 'item_type' => 'finished_good', 'standard_cost' => '100.0000']);
        $s = app(PriceNegotiationService::class);
        $this->assertSame('100.00', $s->reviewCost($p)['cost']);
        $r = $s->offer(null, $this->actor('sales_officer'), $this->terms($p, $this->buyer()));
        $this->expectException(BusinessRuleException::class);
        $s->approve($r, $this->actor('finance_officer'), $this->approval($p, $r));
    }

    public function test_ppc_cannot_directly_change_financial_cost_and_floor(): void
    {
        $ppc = $this->actor('ppc_head');
        $this->assertFalse($ppc->hasPermission('inventory.costs.approve'));
        $p = $this->product();
        $this->actingAs($ppc)->putJson('/api/v1/crm/products/'.$p->hash_id, ['standard_cost' => '1.00'])->assertForbidden();
        $this->assertSame('15.00', $p->fresh()->minimum_selling_price);
    }

    public function test_rejected_receipt_review_can_be_renewed_with_history(): void
    {
        $i = Item::factory()->create(['standard_cost' => '10.0000']);
        $grn = GoodsReceiptNote::factory()->create(['status' => 'accepted', 'accepted_at' => now()]);
        $po = PurchaseOrderItem::create(['purchase_order_id' => $grn->purchase_order_id, 'item_id' => $i->id, 'description' => 'Audit receipt', 'quantity' => '5', 'unit' => 'pcs', 'unit_price' => '12.00', 'total' => '60.00']);
        $line = $grn->items()->create(['purchase_order_item_id' => $po->id, 'location_id' => WarehouseLocation::factory()->create()->id, 'item_id' => $i->id, 'quantity_received' => '5', 'quantity_accepted' => '5', 'unit_cost' => '12.0000']);
        $s = app(ItemCostReviewService::class);
        $r = $s->capture($line, '12.0000');
        $line->update(['unit_cost' => '14.0000']);
        $s->decide($r, $this->actor('finance_officer'), 'reject', 'Stale receipt');
        $again = $s->renew($r, $this->actor('finance_officer'), 'Review corrected receipt');
        $this->assertSame($r->id, $again->id);
        $this->assertSame('pending', $again->status->value);
        $this->assertSame('14.0000', $again->proposed_cost);
        $this->assertSame(2, $again->version);
        $this->actingAs($this->actor('finance_officer'))->postJson('/api/v1/inventory/cost-reviews/'.$r->hash_id.'/decide', ['decision' => 'approve', 'notes' => 'Old revision', 'version' => 1])->assertUnprocessable();
        $this->assertCount(1, $again->history);
    }

    public function test_custom_finance_approver_receives_handoff(): void
    {
        $role = Role::create(['name' => 'Custom pricing finance', 'slug' => 'audit_finance', 'is_system' => false]);
        $role->permissions()->attach(Permission::where('slug', 'crm.price_agreements.approve')->value('id'));
        $finance = User::factory()->create(['role_id' => $role->id]);
        $this->assertTrue($finance->hasPermission('crm.price_agreements.approve'));
        $s = app(PriceNegotiationService::class);
        $p = $this->product();
        $s->offer(null, $this->actor('sales_officer'), $this->terms($p, $this->buyer()));
        $this->assertSame(1, DB::table('notifications')->where('type', 'pricing.negotiation')->where('notifiable_id', $finance->id)->count());
        $finance = $this->actor('finance_officer');
        DB::table('user_permission_overrides')->insert(['user_id' => $finance->id, 'permission_id' => Permission::where('slug', 'crm.price_agreements.approve')->value('id'), 'type' => 'revoke', 'granted_by' => $finance->id, 'reason' => 'Approval authority withdrawn', 'created_at' => now(), 'updated_at' => now()]);
        $s->offer(null, $this->actor('sales_officer'), $this->terms($this->product(), $this->buyer()));
        $this->assertSame(0, DB::table('notifications')->where('type', 'pricing.negotiation')->where('notifiable_id', $finance->id)->count());
    }

    public function test_unassigned_private_product_is_blocked_before_offer(): void
    {
        $s = app(PriceNegotiationService::class);
        $p = $this->product();
        $p->update(['portal_visibility' => 'restricted']);
        $b = $this->buyer();
        $this->expectException(BusinessRuleException::class);
        $s->offer(null, $this->actor('sales_officer'), $this->terms($p, $b));
    }

    public function test_stalled_pricing_outbox_changes_health_status(): void
    {
        $user = $this->actor('system_admin');
        $this->mock(SettingsService::class)->shouldReceive('get')->with('quality.rollout.eligible_item_types', '__missing_quality_rollout_types__')->andReturn(['raw_material'])->getMock()->shouldReceive('requiredInt')->with('quality.rollout.pending_grn_grace_minutes', 0)->andReturn(0);
        $this->mock(ActionCenterService::class)->shouldReceive('for')->once()->andReturn(['summary' => ['overdue' => 0]]);
        SchedulerTickRun::create(['id' => (string) Str::uuid(), 'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(), 'failed_tasks' => 0, 'exit_code' => 0]);
        DB::table('event_outbox')->insert(['id' => (string) Str::uuid(), 'event_type' => BomPricingReviewRequested::class, 'payload' => '{}', 'dedupe_key' => 'audit:stalled', 'status' => 'pending', 'attempts' => 9, 'available_at' => now()->subDays(2), 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $health = app(RolloutHealthService::class)->summary($user);
        $this->assertSame('attention', $health['status']);
        $this->assertSame(1, $health['automation']['pending_pricing']);
    }

    public function test_cost_change_between_display_and_approval_is_blocked(): void
    {
        $s = app(PriceNegotiationService::class);
        $p = $this->product();
        $r = $s->offer(null, $this->actor('sales_officer'), $this->terms($p, $this->buyer()));
        $approval = $this->approval($p, $r);
        $p->update(['standard_cost' => '11.00']);
        $this->actingAs($this->actor('finance_officer'))->postJson('/api/v1/crm/price-requests/'.$r->hash_id.'/approve', $approval)->assertUnprocessable();
        $this->assertSame('pending_finance', $r->fresh()->status->value);
    }

    public function test_finance_approves_only_ppc_standard_cost_proposal_and_preserves_audit(): void
    {
        $p = $this->product();
        $ppc = $this->actor('ppc_head');
        $finance = $this->actor('finance_officer');
        $proposal = $this->actingAs($ppc)->postJson('/api/v1/crm/products/'.$p->hash_id.'/financial-proposal', ['standard_cost' => '12.00', 'notes' => 'Material cost increase'])->assertOk()->json('data.financial_change_request');
        $this->assertSame($ppc->hash_id, $proposal['proposed_by']);
        $this->assertSame('10.00', $p->fresh()->standard_cost);
        $this->postJson('/api/v1/crm/products/'.$p->hash_id.'/financial-decision', ['proposal_id' => $proposal['id'], 'decision' => 'approve', 'notes' => 'Own change'])->assertForbidden();
        $this->actingAs($finance)->getJson('/api/v1/crm/products/'.$p->hash_id)->assertOk();
        $this->postJson('/api/v1/crm/products/'.$p->hash_id.'/financial-decision', ['proposal_id' => $proposal['id'], 'decision' => 'approve', 'notes' => 'Finance checked new cost'])->assertOk()->assertJsonPath('data.standard_cost', '12.00')->assertJsonPath('data.minimum_selling_price', '15.00')->assertJsonPath('data.financial_change_request', null);
        $this->assertTrue(AuditLog::where('model_type', Product::class)->where('model_id', $p->id)->get()->contains(fn ($log) => ($log->new_values['financial_change_request']['decision_notes'] ?? null) === 'Finance checked new cost'));
    }

    public function test_open_negotiation_protects_product_identity_and_archiving(): void
    {
        $p = $this->product();
        app(PriceNegotiationService::class)->offer(null, $this->actor('sales_officer'), $this->terms($p, $this->buyer()));
        $this->actingAs($this->actor('ppc_head'));
        $this->putJson('/api/v1/crm/products/'.$p->hash_id, ['part_number' => 'CHANGED-ID'])->assertUnprocessable();
        $this->putJson('/api/v1/crm/products/'.$p->hash_id, ['is_active' => false])->assertUnprocessable();
        $this->deleteJson('/api/v1/crm/products/'.$p->hash_id)->assertUnprocessable();
    }

    public function test_archived_item_review_can_be_rejected(): void
    {
        $i = Item::factory()->create(['standard_cost' => '10.0000']);
        $grn = GoodsReceiptNote::factory()->create(['status' => 'accepted', 'accepted_at' => now()]);
        $po = PurchaseOrderItem::create(['purchase_order_id' => $grn->purchase_order_id, 'item_id' => $i->id, 'description' => 'Receipt', 'quantity' => '5', 'unit' => 'PCS', 'unit_price' => '12', 'total' => '60']);
        $line = $grn->items()->create(['purchase_order_item_id' => $po->id, 'location_id' => WarehouseLocation::factory()->create()->id, 'item_id' => $i->id, 'quantity_received' => '5', 'quantity_accepted' => '5', 'unit_cost' => '12.0000']);
        $r = app(ItemCostReviewService::class)->capture($line, '12.0000');
        $i->delete();
        $this->actingAs($this->actor('finance_officer'))->postJson('/api/v1/inventory/cost-reviews/'.$r->hash_id.'/decide', ['version' => 1, 'decision' => 'reject', 'notes' => 'Archived source'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_withdrawal_and_expiration_expose_customer_safe_reasons(): void
    {
        $s = app(PriceNegotiationService::class);
        $p = $this->product();
        $b = $this->buyer();
        $sales = $this->actor('sales_officer');
        $r = $s->offer(null, $sales, $this->terms($p, $b));
        $s->withdraw($r, $sales, 'Customer requested different quantity');
        $this->actingAs($b, 'customer_portal')->getJson('/api/v1/b2b/customer/price-requests')->assertOk()->assertJsonPath('data.0.decision_reason', 'Customer requested different quantity')->assertJsonMissingPath('data.0.history');
        $r = $s->offer(null, $sales, $this->terms($p, $b));
        $this->travel(32)->days();
        $s->expire();
        $this->assertSame('expired', $r->fresh()->status->value);
        $this->assertSame('expired', collect($r->fresh()->history)->last()['action']);
        $this->travelBack();
    }

    public function test_inactive_manufacturing_bom_does_not_make_cost_complete(): void
    {
        $p = $this->product();
        Bom::create(['product_id' => $p->id, 'version' => 1, 'is_active' => false, 'total_cost' => '10.00']);
        $this->assertTrue(app(PriceNegotiationService::class)->reviewCost($p)['incomplete']);
    }

    public function test_stale_bom_changes_health_even_after_outbox_published(): void
    {
        $user = $this->actor('system_admin');
        $p = $this->product();
        Bom::create(['product_id' => $p->id, 'version' => 1, 'is_active' => true, 'total_cost' => '10.00', 'costed_at' => null]);
        $this->mock(SettingsService::class)->shouldReceive('get')->with('quality.rollout.eligible_item_types', '__missing_quality_rollout_types__')->andReturn(['raw_material'])->getMock()->shouldReceive('requiredInt')->with('quality.rollout.pending_grn_grace_minutes', 0)->andReturn(0);
        $this->mock(ActionCenterService::class)->shouldReceive('for')->once()->andReturn(['summary' => ['overdue' => 0]]);
        SchedulerTickRun::create(['id' => (string) Str::uuid(), 'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(), 'failed_tasks' => 0, 'exit_code' => 0]);
        $health = app(RolloutHealthService::class)->summary($user);
        $this->assertSame('attention', $health['status']);
        $this->assertSame(1, $health['automation_issues']['stale_boms']);
        $this->assertSame(0, $health['automation']['failed_pricing']);
    }

    public function test_custom_cost_approver_can_read_financial_handoff_without_product_management(): void
    {
        $role = Role::create(['name' => 'Custom cost reviewer', 'slug' => 'custom_cost_reviewer', 'is_system' => false]);
        $role->permissions()->attach(Permission::where('slug', 'inventory.costs.approve')->value('id'));
        $reviewer = User::factory()->create(['role_id' => $role->id]);
        $p = $this->product();
        $this->actingAs($reviewer)->getJson('/api/v1/crm/products/'.$p->hash_id)->assertOk();
        $this->putJson('/api/v1/crm/products/'.$p->hash_id, ['name' => 'Unauthorized maintenance'])->assertForbidden();
    }
}
