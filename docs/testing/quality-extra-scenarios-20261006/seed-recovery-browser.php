<?php
require '/var/www/vendor/autoload.php';
$app = require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'ogami_test_audit_20261002') throw new RuntimeException('Isolated audit database required.');
use App\Modules\Auth\Models\{User, Role};
use App\Modules\Inventory\Models\{Item, Warehouse, WarehouseZone, WarehouseLocation, GoodsReceiptNote, GrnItem};
use App\Modules\Inventory\Enums\{WarehouseZoneType, StockMovementType};
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Quality\Services\{InspectionService, ItemQualityPlanService};
use App\Modules\Purchasing\Models\PurchaseOrderItem;
$stamp = date('His').bin2hex(random_bytes(2));
$accounts = [];
foreach (['maker'=>'qc_inspector', 'checker'=>'qc_inspector', 'warehouse'=>'warehouse_staff'] as $key=>$slug) {
    $accounts[$key] = User::factory()->create(['role_id'=>Role::where('slug',$slug)->firstOrFail()->id,'is_active'=>true,
        'email'=>"qc-recovery-$key-$stamp@ogami.test",'name'=>ucfirst($key).' recovery tester','password'=>Illuminate\Support\Facades\Hash::make('password')]);
}
$warehouse = Warehouse::factory()->create(['is_active'=>true]);
$raw = WarehouseZone::factory()->create(['warehouse_id'=>$warehouse->id, 'zone_type'=>'raw_materials']);
$quarantine = WarehouseZone::factory()->create(['warehouse_id'=>$warehouse->id, 'zone_type'=>'quarantine']);
$source = WarehouseLocation::factory()->create(['zone_id'=>$raw->id,'is_active'=>true]);
$holdLocation = WarehouseLocation::factory()->create(['zone_id'=>$quarantine->id,'is_active'=>true]);
$item = Item::factory()->create(['unit_of_measure'=>'PCS','is_active'=>true,'code'=>'RECOVERY-'.$stamp]);
$grn = GoodsReceiptNote::factory()->create(['status'=>'accepted','received_by'=>$accounts['maker']->id]);
$poi = PurchaseOrderItem::create(['purchase_order_id'=>$grn->purchase_order_id,'item_id'=>$item->id,'description'=>'Recovery browser proof','quantity'=>'100','unit'=>'PCS','unit_price'=>'1','total'=>'100']);
$line = GrnItem::create(['goods_receipt_note_id'=>$grn->id,'purchase_order_item_id'=>$poi->id,'item_id'=>$item->id,'location_id'=>$source->id,'quantity_received'=>'100','quantity_accepted'=>'100','unit_cost'=>'1','material_lot_number'=>'RECOVERY-LOT-'.$stamp]);
$plan = app(ItemQualityPlanService::class)->createRevision($item, ['capture_method'=>'measurement','sampling_method'=>'full','parameters'=>[
    ['parameter_name'=>'Condition','acceptance_criterion'=>'No damage.','sort_order'=>0]]], $accounts['maker']);
$inspection = app(InspectionService::class)->createIncomingFromPlan($plan,$line,$grn,$accounts['maker']);
$inspection->forceFill(['status'=>'passed','reviewed_by'=>$accounts['checker']->id,'reviewed_at'=>now()])->save();
app(StockMovementService::class)->move(new StockMovementInput(type:StockMovementType::Opening,itemId:$item->id,quantity:'100',toLocationId:$source->id,unitCost:'1',createdBy:$accounts['maker']->id,lotNumber:$line->material_lot_number));
// Existing reservation makes physical quarantine unavailable without changing global warehouse setup.
App\Modules\Inventory\Models\StockLevel::where('item_id',$item->id)->where('location_id',$source->id)->update(['reserved_quantity'=>'100']);
$ncr = app(App\Modules\Quality\Services\NcrService::class)->create(['source'=>'inspection_fail','severity'=>'high','inspection_id'=>$inspection->id,
    'affected_quantity'=>1,'defect_description'=>'Noncritical cosmetic mark detected after receipt acceptance.'], $accounts['maker']);
App\Modules\Inventory\Models\StockLevel::where('item_id',$item->id)->where('location_id',$source->id)->update(['reserved_quantity'=>'0']);
echo json_encode(['accounts'=>array_map(fn($u)=>$u->email,$accounts),'ncr'=>$ncr->hash_id,'source'=>$source->hash_id,'inspection'=>$inspection->hash_id],JSON_PRETTY_PRINT);
