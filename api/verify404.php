<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$noop = new class { public function handle($r,$n,...$a){ return $n($r);} };
$app->instance(\Illuminate\Routing\Middleware\ThrottleRequests::class, $noop);
$admin = \App\Modules\Auth\Models\User::whereHas('role', fn($q)=>$q->where('slug','system_admin'))->first();
$doc = \App\Common\Models\Document::first();
$dp  = \App\Modules\SupplyChain\Models\DeliveryProof::first();
$targets = ["api/v1/documents/{$doc->hash_id}/view", "api/v1/documents/{$doc->hash_id}/download"];
if ($dp) $targets[] = "api/v1/supply-chain/deliveries/{$dp->delivery->hash_id}/proofs/{$dp->hash_id}/view";
foreach ($targets as $t) {
    \Illuminate\Support\Facades\Auth::shouldUse('web');
    \Illuminate\Support\Facades\Auth::login($admin);
    $req = \Illuminate\Http\Request::create('/'.$t,'GET',[],[],[],['HTTP_ACCEPT'=>'application/json','HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
    $req->setUserResolver(fn()=>$admin);
    $req->setLaravelSession($app['session']->driver());
    $resp = $kernel->handle($req);
    echo str_pad((string)$resp->getStatusCode(),5)." /$t\n      ".substr($resp->getContent(),0,120)."\n";
}
