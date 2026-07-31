<?php
// Route integrity audit: controller/method existence, middleware alias resolution,
// duplicate names, model-binding sanity.
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$router = $app['router'];
$router->getRoutes()->refreshNameLookups();
$aliases = (function () use ($app) {
    $r = new ReflectionClass(\Illuminate\Foundation\Http\Kernel::class);
    $k = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $p = $r->getProperty('middlewareAliases'); $p->setAccessible(true);
    return $p->getValue($k);
})();
$groups = (function () use ($app) {
    $r = new ReflectionClass(\Illuminate\Foundation\Http\Kernel::class);
    $k = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $p = $r->getProperty('middlewareGroups'); $p->setAccessible(true);
    return $p->getValue($k);
})();

$errors = [];
$names = [];
$uriMethods = [];

foreach ($router->getRoutes() as $route) {
    $uri = $route->uri();
    $action = $route->getActionName();
    $methods = implode('|', $route->methods());
    $label = "$methods /$uri  [$action]";

    // 1. controller + method exists
    if ($action !== 'Closure' && str_contains($action, '@')) {
        [$class, $method] = explode('@', $action, 2);
        if (! class_exists($class)) {
            $errors[] = ["MISSING_CONTROLLER", $label, "class $class not found"];
        } elseif (! method_exists($class, $method)) {
            $errors[] = ["MISSING_METHOD", $label, "$class::$method() not found"];
        } else {
            $rm = new ReflectionMethod($class, $method);
            if (! $rm->isPublic()) {
                $errors[] = ["NON_PUBLIC_ACTION", $label, "$class::$method() is not public"];
            }
        }
    } elseif ($action !== 'Closure' && class_exists($action)) {
        if (! method_exists($action, '__invoke')) {
            $errors[] = ["MISSING_INVOKE", $label, "$action has no __invoke()"];
        }
    } elseif ($action !== 'Closure' && ! str_contains($action, '@') && ! class_exists($action)) {
        $errors[] = ["MISSING_CONTROLLER", $label, "class $action not found"];
    }

    // 2. middleware aliases resolve
    foreach ($route->gatherMiddleware() as $mw) {
        if (! is_string($mw)) continue;
        $base = explode(':', $mw)[0];
        if (isset($aliases[$base]) || isset($groups[$base])) continue;
        if (class_exists($base)) continue;
        $errors[] = ["UNKNOWN_MIDDLEWARE", $label, "middleware '$mw' does not resolve"];
    }

    // 3. duplicate route names
    if ($n = $route->getName()) {
        if (isset($names[$n])) $errors[] = ["DUPLICATE_NAME", $label, "name '$n' also on {$names[$n]}"];
        $names[$n] = $label;
    }

    // 4. duplicate method+uri
    foreach ($route->methods() as $m) {
        $key = "$m /$uri";
        if (isset($uriMethods[$key])) {
            $errors[] = ["SHADOWED_ROUTE", $label, "$key already declared by {$uriMethods[$key]}"];
        } else {
            $uriMethods[$key] = $action;
        }
    }
}

foreach ($errors as [$type, $label, $msg]) {
    echo str_pad($type, 22)." | $label\n    -> $msg\n";
}
echo "\n=== ".count($errors)." route problems across ".count($router->getRoutes())." routes ===\n";
