<?php
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'ogami_test_audit_20261002' || !config('inspection.checklist_capture_enabled')) throw new RuntimeException('Isolated audit database with checklist capture required.');
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\Permission;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\InspectionSpecService;
use App\Modules\Quality\Services\ItemQualityPlanService;
$stamp = date('His').bin2hex(random_bytes(2));
$role=Role::firstOrCreate(['slug'=>'qc_inspector'], ['name'=>'QC Inspector']);
foreach(['quality.inspections.manage','quality.inspections.review','quality.inspections.view','quality.specs.manage','quality.specs.view','inventory.view','crm.products.view'] as $slug) {
 $permission=Permission::firstOrCreate(['slug'=>$slug],['name'=>$slug,'module'=>explode('.',$slug)[0]]);
 $role->permissions()->syncWithoutDetaching([$permission->id]);
}
$accounts=[];
foreach(['maker','checker'] as $person) {
 $accounts[$person]=User::factory()->create(['email'=>"checklist-$person-$stamp@ogami.test", 'name'=>"Checklist ".ucfirst($person), 'role_id'=>$role->id,'is_active'=>true,'password'=>Illuminate\Support\Facades\Hash::make('password')]);
}
foreach (['employee', 'warehouse_staff', 'ppc_head'] as $slug) {
 $assignedRole=Role::where('slug',$slug)->firstOrFail();
 $accounts[$slug]=User::factory()->create(['email'=>"checklist-$slug-$stamp@ogami.test",'name'=>"Checklist $slug",'role_id'=>$assignedRole->id,'is_active'=>true,'password'=>Illuminate\Support\Facades\Hash::make('password')]);
}
$maker=$accounts['maker'];
auth()->setUser($maker);
$checks=[['parameter_name'=>'Surface condition','acceptance_criterion'=>'No cracks or contamination.'], ['parameter_name'=>'Fit check','acceptance_criterion'=>'Fits the approved inspection gauge.','is_critical'=>true,'parameter_type'=>'dimensional','unit_of_measure'=>'mm','nominal_value'=>'10.0000','tolerance_min'=>'9.9000','tolerance_max'=>'10.1000'], ['parameter_name'=>'Identification','acceptance_criterion'=>'Part identification matches the production or supplier record.']];
$checks=array_map(fn($check,$index)=>[...$check,'sort_order'=>$index],$checks,array_keys($checks));
$product=Product::factory()->create(['part_number'=>"CHECKLIST-$stamp",'name'=>'Checklist browser proof','unit_of_measure'=>'PCS']);
$spec=app(InspectionSpecService::class)->upsertForProduct($product->id,$checks,$maker->id,'Isolated browser test only.','checklist');
$service=app(InspectionService::class);
$wo=WorkOrder::factory()->create(['product_id'=>$product->id,'status'=>'in_progress','created_by'=>$maker->id,'quantity_target'=>100,'quantity_produced'=>100,'quantity_good'=>100,'quantity_rejected'=>0]);
$output=WorkOrderOutput::create(['work_order_id'=>$wo->id,'shift'=>'day','good_count'=>100,'reject_count'=>0,'recorded_by'=>$maker->id,'recorded_at'=>now()]);
$inspectionIds=[];
foreach(['in_process','outgoing','customer_return','supplier_return'] as $stage) {
  $source = [];
 if (in_array($stage, ['customer_return', 'supplier_return'], true)) {
  $return=App\Modules\ReturnManagement\Models\ReturnRequest::create(['rma_number'=>"RMA-$stage-$stamp",'type'=>$stage,'status'=>'received','received_at'=>now(),'created_by'=>$maker->id]);
  $return->items()->create(['product_id'=>$product->id,'quantity'=>100,'returned_quantity'=>100,'receipt_recorded'=>true,'unit_price'=>1,'total'=>100]);
  $source=['entity_type'=>'return_request','entity_id'=>$return->id];
 }
$inspection=$service->create(['stage'=>$stage,'product_id'=>$product->id,'batch_quantity'=>100,...$source,...($stage==='outgoing'?['work_order_output_id'=>$output->id]:($stage==='in_process'?['entity_type'=>'work_order','entity_id'=>$wo->id]:[]))],$maker);
 $inspectionIds[$stage]=$inspection->hash_id;
}
$item=Item::factory()->create(['unit_of_measure'=>'KG','code'=>"QC-BULK-$stamp"]);
$grn=GoodsReceiptNote::factory()->create(['received_by'=>$maker->id]);
$poLine=PurchaseOrderItem::create(['purchase_order_id'=>$grn->purchase_order_id,'item_id'=>$item->id,'description'=>'Bulk browser proof','quantity'=>'125.500','unit'=>'KG','unit_price'=>'1.00','total'=>'125.50']);
$line=GrnItem::create(['goods_receipt_note_id'=>$grn->id,'purchase_order_item_id'=>$poLine->id,'item_id'=>$item->id,'location_id'=>WarehouseLocation::factory()->create()->id,'quantity_received'=>'125.500','unit_cost'=>'1.00']);
$plan=app(ItemQualityPlanService::class)->createRevision($item,['capture_method'=>'measurement','sampling_method'=>'full','parameters'=>$checks],$maker);
$inspection=$service->createIncomingFromPlan($plan,$line,$grn,$maker);
$inspectionIds['incoming']=$inspection->hash_id;
echo json_encode(['accounts'=>array_map(fn($u)=>$u->email,$accounts),'inspections'=>$inspectionIds,'spec'=>$spec->hash_id,'item'=>$item->hash_id,'grn'=>$grn->hash_id], JSON_PRETTY_PRINT);
