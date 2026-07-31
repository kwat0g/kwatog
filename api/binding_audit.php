<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$router = $app['router'];
$errors = [];
$seen = [];

foreach ($router->getRoutes() as $route) {
    $action = $route->getActionName();
    if ($action === 'Closure') {
        continue;
    }
    [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
    if (! class_exists($class) || ! method_exists($class, $method)) {
        continue;
    }
    $rm = new ReflectionMethod($class, $method);
    $params = [];
    foreach ($rm->getParameters() as $p) {
        $t = $p->getType();
        if (! $t instanceof ReflectionNamedType || $t->isBuiltin()) {
            continue;
        }
        $tn = $t->getName();
        if (! class_exists($tn)) {
            continue;
        }
        if (! is_subclass_of($tn, Model::class)) {
            continue;
        }
        $params[$p->getName()] = $tn;
    }
    $uriParams = [];
    preg_match_all('/\{(\w+)\??\}/', $route->uri(), $m);
    $uriParams = $m[1];
    $label = implode('|', array_diff($route->methods(), ['HEAD'])).' /'.$route->uri();

    foreach ($uriParams as $up) {
        if (! isset($params[$up])) {
            // route param has no model-typed arg -> either scalar (fine) or mismatch
            continue;
        }
        $modelClass = $params[$up];
        $uses = class_uses_recursive($modelClass);
        $hasHash = false;
        foreach ($uses as $u) {
            if (str_contains($u, 'HasHashId')) {
                $hasHash = true;
            }
        }
        $k = $modelClass;
        if (! $hasHash && ! isset($seen[$k])) {
            $seen[$k] = true;
            $errors[] = ['BINDING_NO_HASHID', $label, "$modelClass bound as {{$up}} but lacks HasHashId -> hash IDs from API will 404"];
        }
    }
    // model-typed args with NO matching uri param -> Laravel injects empty model (silent bug)
    foreach ($params as $pn => $pc) {
        if (! in_array($pn, $uriParams, true) && ! in_array(Str::snake($pn), $uriParams, true)) {
            $errors[] = ['ARG_NOT_IN_URI', $label, "$class::$method() has model arg \$$pn ($pc) with no matching {{$pn}} in URI"];
        }
    }
}
foreach ($errors as [$t,$l,$m]) {
    echo str_pad($t, 20)." | $l\n    -> $m\n";
}
echo "\n=== ".count($errors)." binding problems ===\n";
exit($errors === [] ? 0 : 1);
