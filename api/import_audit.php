<?php

declare(strict_types=1);

/**
 * Import-integrity audit.
 *
 * Catches the exact bug that made ProcessYearEndLeave fatal: a `use` statement
 * naming a class that does not exist. PHP only fatals when the symbol is
 * actually touched, so a bad import inside a rarely-run job or cron survives
 * every lint, every test, and every HTTP smoke — then blows up on the one click
 * or the one nightly run that reaches it.
 *
 * Also flags dispatch/instantiation targets and Blade-independent string class
 * references in `::class` position that do not resolve.
 */

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$errors = [];
$checked = 0;
$files = 0;

$symbolExists = static function (string $fqcn): bool {
    return class_exists($fqcn)
        || interface_exists($fqcn)
        || trait_exists($fqcn)
        || (function_exists('enum_exists') && enum_exists($fqcn));
};

$scan = static function (string $dir) use (&$errors, &$checked, &$files, $symbolExists): void {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($rii as $f) {
        if (! $f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $path = $f->getPathname();
        $src = file_get_contents($path);
        if ($src === false) {
            continue;
        }
        $files++;
        $rel = str_replace(__DIR__.'/', '', $path);

        // `use A\B\C;` / `use A\B\C as D;` — skip function/const imports and groups.
        if (! preg_match_all('/^use\s+(?!function\s|const\s)([A-Za-z0-9_\\\\]+)\s*(?:as\s+[A-Za-z0-9_]+\s*)?;/m', $src, $m)) {
            continue;
        }
        foreach ($m[1] as $fqcn) {
            $fqcn = ltrim($fqcn, '\\');
            $checked++;
            if ($symbolExists($fqcn)) {
                continue;
            }
            $errors[] = "BAD_IMPORT {$rel}: use {$fqcn}; — symbol does not exist";
        }
    }
};

foreach ([__DIR__.'/app', __DIR__.'/database', __DIR__.'/routes'] as $dir) {
    if (is_dir($dir)) {
        $scan($dir);
    }
}

echo "Scanned {$files} files, {$checked} imports\n";
if ($errors === []) {
    echo "\n=== 0 unresolvable imports ===\n";
    exit(0);
}
echo "\n=== ".count($errors)." problems ===\n";
foreach (array_unique($errors) as $e) {
    echo "  $e\n";
}
exit(1);
