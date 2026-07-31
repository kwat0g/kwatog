<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

$admin = \App\Modules\Auth\Models\User::whereHas('role', fn($q)=>$q->where('slug','system_admin'))->first();
if (!$admin) { fwrite(STDERR,"no admin\n"); exit(1); }

// Neutralize throttling so the sweep itself doesn't trip rate limits.
$noop = new class { public function handle($r, $n, ...$a) { return $n($r); } };
$app->instance(\Illuminate\Routing\Middleware\ThrottleRequests::class, $noop);
$app->instance(\Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class, $noop);

$router = $app['router'];

/** resolve a live hash id for a model class */
$idCache = [];
$resolveId = function (string $modelClass) use (&$idCache) {
    if (array_key_exists($modelClass, $idCache)) return $idCache[$modelClass];
    try {
        $m = $modelClass::query()->first();
        if (!$m) return $idCache[$modelClass] = null;
        $rk = $m->getRouteKeyName();
        $val = method_exists($m,'getHashIdAttribute') && $rk === $m->getKeyName() ? $m->hash_id : $m->getRouteKey();
        return $idCache[$modelClass] = (string) $val;
    } catch (\Throwable $e) { return $idCache[$modelClass] = null; }
};

$results = [];
$skipped = [];

foreach ($router->getRoutes() as $route) {
    if (!in_array('GET', $route->methods(), true)) continue;
    $uri = $route->uri();
    if (!str_starts_with($uri, 'api/v1/')) continue;
    if (str_contains($uri, 'broadcasting')) continue;
    $action = $route->getActionName();
    if ($action === 'Closure') { $params = []; }
    else {
        [$class, $method] = str_contains($action,'@') ? explode('@',$action,2) : [$action,'__invoke'];
        if (!class_exists($class) || !method_exists($class,$method)) continue;
        $rm = new ReflectionMethod($class,$method);
        $params = [];
        foreach ($rm->getParameters() as $p) {
            $t = $p->getType();
            if ($t instanceof ReflectionNamedType && !$t->isBuiltin() && class_exists($t->getName()) && is_subclass_of($t->getName(), Model::class)) {
                $params[\Illuminate\Support\Str::snake($p->getName())] = $t->getName();
                $params[$p->getName()] = $t->getName();
            }
        }
    }

    preg_match_all('/\{(\w+)(\??)\}/', $uri, $m, PREG_SET_ORDER);
    $path = $uri; $unresolved = null;
    foreach ($m as [$full, $name, $opt]) {
        if ($opt === '?') { $path = str_replace('/'.$full, '', $path); continue; }
        $modelClass = $params[$name] ?? null;
        $val = $modelClass ? $resolveId($modelClass) : null;
        if ($val === null) { $unresolved = $name.($modelClass ? " ($modelClass: no rows)" : ' (scalar param)'); break; }
        $path = str_replace($full, $val, $path);
    }
    if ($unresolved !== null) { $skipped[] = ["GET /$uri", $unresolved]; continue; }

    Auth::shouldUse('web');
    Auth::login($admin);
    $req = Request::create('/'.$path, 'GET', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    ]);
    $req->setUserResolver(fn() => $admin);
    $req->setLaravelSession($app['session']->driver());
    try {
        $resp = $kernel->handle($req);
        $status = $resp->getStatusCode();
        $body = $resp->getContent();
    } catch (\Throwable $e) {
        $status = 599; $body = json_encode(['message'=>$e->getMessage(),'file'=>$e->getFile().':'.$e->getLine()]);
    }
    if ($status >= 500) {
        $d = json_decode($body, true);
        $results[] = [$status, "GET /$uri", $d['message'] ?? substr(strip_tags((string)$body),0,200), ($d['file'] ?? '').(isset($d['line'])?':'.$d['line']:'')];
    } elseif ($status >= 400 && $status !== 404) {
        $d = json_decode($body, true);
        $results[] = [$status, "GET /$uri", $d['message'] ?? '', ''];
    }
    unset($resp);
}

echo "=== NON-OK RESPONSES (".count($results).") ===\n";
usort($results, fn($a,$b)=>$b[0]<=>$a[0]);
foreach ($results as [$s,$r,$msg,$file]) {
    echo str_pad((string)$s,4)." $r\n      $msg\n".($file?"      @ $file\n":"");
}
echo "\n=== SKIPPED (no test data / scalar param): ".count($skipped)." ===\n";
foreach ($skipped as [$r,$why]) echo "  $r   [$why]\n";
