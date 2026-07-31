<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Collect permission slugs that EXIST in DB
$existing = [];
try {
    $existing = DB::table('permissions')->pluck('slug')->all();
} catch (Throwable $e) {
    echo 'DB permissions read failed: '.$e->getMessage()."\n";
}
$existing = array_flip($existing);
echo 'Seeded permissions in DB: '.count($existing)."\n";

// Collect permission strings referenced in code
$refs = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/app'));
foreach ($rii as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $src = file_get_contents($f->getPathname());
    $rel = str_replace(__DIR__.'/', '', $f->getPathname());
    // ->can('x'), can:x middleware, permission:x middleware, Gate::allows('x')
    if (preg_match_all("/->can\(\s*'([a-z0-9_.\-]+)'/i", $src, $m)) {
        foreach ($m[1] as $p) {
            $refs[$p][] = $rel;
        }
    }
    if (preg_match_all("/->cannot\(\s*'([a-z0-9_.\-]+)'/i", $src, $m)) {
        foreach ($m[1] as $p) {
            $refs[$p][] = $rel;
        }
    }
    if (preg_match_all("/hasPermission(?:To)?\(\s*'([a-z0-9_.\-]+)'/i", $src, $m)) {
        foreach ($m[1] as $p) {
            $refs[$p][] = $rel;
        }
    }
    if (preg_match_all("/Gate::(?:allows|denies|authorize)\(\s*'([a-z0-9_.\-]+)'/i", $src, $m)) {
        foreach ($m[1] as $p) {
            $refs[$p][] = $rel;
        }
    }
    if (preg_match_all("/authorize\(\s*'([a-z0-9_.\-]+\.[a-z0-9_.\-]+)'/i", $src, $m)) {
        foreach ($m[1] as $p) {
            $refs[$p][] = $rel;
        }
    }
}

// Include permission gates referenced by the SPA (route guards, sidebar,
// conditional actions). Matching against the known catalog avoids treating
// unrelated dotted strings such as query keys as permissions.
$spaRoot = dirname(__DIR__).'/spa/src';
if (is_dir($spaRoot)) {
    $spaFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($spaRoot));
    foreach ($spaFiles as $f) {
        if (! $f->isFile() || ! in_array($f->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }
        $src = file_get_contents($f->getPathname());
        $rel = str_replace(dirname(__DIR__).'/', '', $f->getPathname());
        foreach (array_keys($existing) as $permission) {
            if (str_contains($src, "'{$permission}'") || str_contains($src, "\"{$permission}\"")) {
                $refs[$permission][] = $rel;
            }
        }
    }
}
// Inspect resolved route middleware instead of matching arbitrary strings such
// as Eloquent's "permission:id,slug" eager-load column selector.
foreach ($app['router']->getRoutes() as $route) {
    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware) || ! preg_match('/^(?:can|permission|permission_any):(.+)$/i', $middleware, $match)) {
            continue;
        }
        foreach (preg_split('/[,|]/', $match[1]) as $permission) {
            $refs[trim($permission)][] = 'route '.$route->uri();
        }
    }
}

ksort($refs);
$missing = [];
foreach ($refs as $p => $files) {
    if ($p === '') {
        continue;
    }
    if (! isset($existing[$p])) {
        $missing[$p] = array_unique($files);
    }
}
echo 'Distinct permission strings referenced in code: '.count($refs)."\n";
echo '=== REFERENCED BUT NOT SEEDED ('.count($missing).") ===\n";
foreach ($missing as $p => $files) {
    echo "  $p\n      ".implode("\n      ", array_slice($files, 0, 4))."\n";
}
$unused = array_diff_key($existing, $refs);
ksort($unused);
echo '=== SEEDED BUT UNREFERENCED ('.count($unused).") ===\n";
foreach (array_keys($unused) as $permission) {
    echo "  $permission\n";
}
exit($missing === [] ? 0 : 1);
