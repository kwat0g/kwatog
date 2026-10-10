<?php
require '/var/www/vendor/autoload.php';
$app=require '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.connections.pgsql.database')!=='ogami_test_audit_20261002') throw new RuntimeException('Isolated database required');
$id=app('hashids')->decode('QE8V2Q6KYp')[0];
$reference=App\Modules\Quality\Models\Inspection::findOrFail($id);
$maker=App\Modules\Auth\Models\User::where('email','checklist-maker-000828ae65@ogami.test')->firstOrFail();
$inspection=app(App\Modules\Quality\Services\InspectionService::class)->create(['stage'=>'in_process','product_id'=>$reference->product_id,'batch_quantity'=>100],$maker);
echo json_encode(['id'=>$inspection->hash_id,'account'=>$maker->email]);
