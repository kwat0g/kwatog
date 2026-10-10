<?php
namespace Tests\Feature\CRM;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use App\Modules\Auth\Models\{Role,User};
use App\Modules\Accounting\Models\Customer;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\{PriceNegotiationService,ProductService};
use App\Modules\Inventory\Models\{Item,GoodsReceiptNote,WarehouseLocation};
use App\Modules\Inventory\Services\ItemCostReviewService;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Common\Exceptions\BusinessRuleException;
use Database\Seeders\RolePermissionSeeder;
class PricingCrossModuleAuditProbeTest extends TestCase {
 use RefreshDatabase;
 protected function setUp():void {parent::setUp();Queue::fake();$this->seed(RolePermissionSeeder::class);}
 private function actor($slug):User {return User::factory()->create(['role_id'=>Role::where('slug',$slug)->value('id')]);}
 private function product():Product {return Product::factory()->create(['standard_cost'=>'10.00','minimum_selling_price'=>'15.00','base_selling_price'=>'50.00','portal_visibility'=>'standard']);}
 private function buyer():CustomerPortalUser {return CustomerPortalUser::create(['customer_id'=>Customer::factory()->create()->id,'name'=>'Audit buyer','email'=>uniqid().'@test.local','password'=>'password','is_active'=>true,'password_changed_at'=>now()]);}
 private function terms(Product $p, CustomerPortalUser $b):array {return ['product_id'=>$p->id,'customer_id'=>$b->customer_id,'price'=>'30.00','pricing_method'=>'flat','effective_from'=>today()->toDateString(),'effective_to'=>today()->addMonth()->toDateString(),'notes'=>'Audit proposal'];}
 public function test_finance_can_approve_revision_it_did_not_load():void {
  $s=app(PriceNegotiationService::class);$p=$this->product();$b=$this->buyer();$sales=$this->actor('sales_officer');$finance=$this->actor('finance_officer');
  $r=$s->offer(null,$sales,$this->terms($p,$b));
  $this->actingAs($finance)->getJson('/api/v1/crm/price-requests/'.$r->hash_id)->assertOk()->assertJsonPath('data.version',1);
  $s->offer($r,$sales,array_merge($this->terms($p,$b),['price'=>'16.00']));
  $this->postJson('/api/v1/crm/price-requests/'.$r->hash_id.'/approve',['notes'=>'Approved price seen at version 1','version'=>1])->assertOk()->assertJsonPath('data.version',2)->assertJsonPath('data.offer.price','16.00');
 }
 public function test_next_day_acceptance_is_blocked_despite_unexpired_offer():void {
  $s=app(PriceNegotiationService::class);$p=$this->product();$b=$this->buyer();$r=$s->offer(null,$this->actor('sales_officer'),$this->terms($p,$b));$s->approve($r,$this->actor('finance_officer'),['notes'=>'Reviewed']);
  $this->travel(1)->days();
  try {$s->respond($r,$b,'accept',1);$this->fail('Expected observed date refusal');} catch(BusinessRuleException $e) {$this->assertStringContainsString('dates must start today',$e->getMessage());}
  $this->assertSame('offered',$r->fresh()->status->value);$this->travelBack();
 }
 public function test_received_finished_good_cost_is_omitted_from_finance_cost_basis():void {
  $p=$this->product();$i=Item::factory()->create(['code'=>$p->part_number,'item_type'=>'finished_good','standard_cost'=>'100.0000']);$s=app(PriceNegotiationService::class);
  $this->assertSame('10.00',$s->reviewCost($p)['cost']);
  $r=$s->offer(null,$this->actor('sales_officer'),$this->terms($p,$this->buyer()));$s->approve($r,$this->actor('finance_officer'),['notes'=>'Review basis']);$this->assertSame('offered',$r->fresh()->status->value);
 }
 public function test_ppc_can_change_financial_cost_and_floor_without_cost_approval():void {
  $ppc=$this->actor('ppc_head');$this->assertFalse($ppc->hasPermission('inventory.costs.approve'));$p=$this->product();
  $this->actingAs($ppc)->putJson('/api/v1/crm/products/'.$p->hash_id,['standard_cost'=>'1.00','minimum_selling_price'=>'1.00'])->assertOk();$this->assertSame('1.00',$p->fresh()->minimum_selling_price);
 }
 public function test_rejected_stale_receipt_review_cannot_be_recreated():void {
  $i=Item::factory()->create(['standard_cost'=>'10.0000']);$grn=GoodsReceiptNote::factory()->create(['status'=>'accepted','accepted_at'=>now()]);
  $po=PurchaseOrderItem::create(['purchase_order_id'=>$grn->purchase_order_id,'item_id'=>$i->id,'description'=>'Audit receipt','quantity'=>'5','unit'=>'pcs','unit_price'=>'12.00','total'=>'60.00']);
  $line=$grn->items()->create(['purchase_order_item_id'=>$po->id,'location_id'=>WarehouseLocation::factory()->create()->id,'item_id'=>$i->id,'quantity_received'=>'5','quantity_accepted'=>'5','unit_cost'=>'12.0000']);
  $s=app(ItemCostReviewService::class);$r=$s->capture($line,'12.0000');$line->update(['unit_cost'=>'14.0000']);$s->decide($r,$this->actor('finance_officer'),'reject','Stale receipt');$again=$s->capture($line->fresh(),'14.0000');
  $this->assertSame($r->id,$again->id);$this->assertSame('rejected',$again->status->value);$this->assertSame('12.0000',$again->proposed_cost);
 }
 public function test_custom_finance_approver_does_not_receive_handoff():void {
  $role=Role::create(['name'=>'Custom pricing finance','slug'=>'audit_finance','is_system'=>false]);$role->permissions()->attach(\App\Modules\Auth\Models\Permission::where('slug','crm.price_agreements.approve')->value('id'));$finance=User::factory()->create(['role_id'=>$role->id]);$this->assertTrue($finance->hasPermission('crm.price_agreements.approve'));
  $s=app(PriceNegotiationService::class);$p=$this->product();$s->offer(null,$this->actor('sales_officer'),$this->terms($p,$this->buyer()));
  $this->assertSame(0,\Illuminate\Support\Facades\DB::table('notifications')->where('type','pricing.negotiation')->where('notifiable_id',$finance->id)->count());
 }
 public function test_unassigned_private_product_can_reach_offer_but_not_acceptance():void {
  $s=app(PriceNegotiationService::class);$p=$this->product();$p->update(['portal_visibility'=>'restricted']);$b=$this->buyer();$r=$s->offer(null,$this->actor('sales_officer'),$this->terms($p,$b));$s->approve($r,$this->actor('finance_officer'),['notes'=>'Reviewed']);
  $this->assertSame('offered',$r->fresh()->status->value);
  try {$s->respond($r,$b,'accept',1);$this->fail('Expected unavailable-product refusal');} catch(BusinessRuleException $e) {$this->assertStringContainsString('availability changed',$e->getMessage());}
 }
 public function test_stalled_pricing_outbox_does_not_change_health_status():void {
  $user=$this->actor('system_admin');
  $this->mock(\App\Common\Services\SettingsService::class)->shouldReceive('get')->with('quality.rollout.eligible_item_types','__missing_quality_rollout_types__')->andReturn(['raw_material'])->getMock()->shouldReceive('requiredInt')->with('quality.rollout.pending_grn_grace_minutes',0)->andReturn(0);
  $this->mock(\App\Modules\Dashboard\Services\ActionCenterService::class)->shouldReceive('for')->once()->andReturn(['summary'=>['overdue'=>0]]);
  \App\Common\Models\SchedulerTickRun::create(['id'=>(string)\Illuminate\Support\Str::uuid(),'status'=>'succeeded','started_at'=>now(),'finished_at'=>now(),'failed_tasks'=>0,'exit_code'=>0]);
  \Illuminate\Support\Facades\DB::table('event_outbox')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'event_type'=>\App\Modules\MRP\Events\BomPricingReviewRequested::class,'payload'=>'{}','dedupe_key'=>'audit:stalled','status'=>'pending','attempts'=>9,'available_at'=>now()->subDays(2),'created_at'=>now()->subDays(2),'updated_at'=>now()->subDays(2)]);
  $health=app(\App\Modules\Dashboard\Services\RolloutHealthService::class)->summary($user);$this->assertSame('healthy',$health['status']);$this->assertSame(1,$health['automation']['pending_pricing']);
 }

}
