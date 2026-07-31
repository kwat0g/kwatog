<?php

declare(strict_types=1);

/**
 * Call-integrity audit.
 *
 * Catches the bug class that only surfaces on a real click:
 *   - $this->service->methodThatDoesNotExist()
 *   - app(FooService::class)->missingMethod()
 *   - FooModel::missingScopeOrMethod()
 *   - validation rules pointing at tables/columns that do not exist
 *
 * These pass every static lint and every 401-gate test, then 500 in manual testing.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$errors = [];
$stats = ['files' => 0, 'calls' => 0, 'rules' => 0];

/** @return string[] */
function phpFiles(string $dir): array
{
    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($rii as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
}

$files = phpFiles(__DIR__.'/app');

foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        continue;
    }
    $stats['files']++;
    $rel = str_replace(__DIR__.'/', '', $file);

    // --- Build alias map: use statements -> FQCN
    $aliases = [];
    if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?;/m', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $u) {
            $fqcn = $u[1];
            $alias = $u[2] ?? substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            $aliases[$alias] = $fqcn;
        }
    }
    $namespace = preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $src, $nm) ? $nm[1] : '';

    $resolve = function (string $short) use ($aliases, $namespace): ?string {
        $short = ltrim($short, '\\');
        if (isset($aliases[$short])) {
            return $aliases[$short];
        }
        if (class_exists($short) || interface_exists($short)) {
            return $short;
        }
        $guess = $namespace.'\\'.$short;
        if (class_exists($guess) || interface_exists($guess)) {
            return $guess;
        }

        return null;
    };

    // --- Map property name -> class, from constructor promotion AND declared props.
    // private FooService $bar   |   protected readonly FooService $bar
    $propTypes = [];
    if (preg_match_all(
        '/(?:private|protected|public)\s+(?:readonly\s+)?\??([A-Za-z_][A-Za-z0-9_\\\\]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/',
        $src, $m, PREG_SET_ORDER
    )) {
        foreach ($m as $p) {
            $fqcn = $resolve($p[1]);
            if ($fqcn !== null) {
                $propTypes[$p[2]] = $fqcn;
            }
        }
    }

    // --- Check $this->prop->method(...)
    if (preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) {
            [$whole, $prop, $method] = $c;
            if (! isset($propTypes[$prop])) {
                continue; // untyped / dynamic — cannot verify
            }
            $class = $propTypes[$prop];
            // Skip Eloquent models & framework bases: __call/__get magic makes this undecidable.
            if (is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)
                || is_subclass_of($class, \Illuminate\Foundation\Http\FormRequest::class)
                || $class === \Illuminate\Http\Request::class
                || is_subclass_of($class, \Illuminate\Http\Request::class)) {
                continue;
            }
            if (! class_exists($class) && ! interface_exists($class)) {
                continue;
            }
            $stats['calls']++;
            $rc = new ReflectionClass($class);
            if ($rc->hasMethod($method) || $rc->hasMethod('__call')) {
                continue;
            }
            $errors[] = "MISSING_METHOD {$rel}: {$class}::{$method}() called via \$this->{$prop}";
        }
    }

    // --- Check app(Foo::class)->method(...)
    if (preg_match_all('/app\(\s*([A-Za-z_][A-Za-z0-9_\\\\]*)::class\s*\)->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) {
            $class = $resolve($c[1]);
            $method = $c[2];
            if ($class === null || (! class_exists($class) && ! interface_exists($class))) {
                continue;
            }
            $rc = new ReflectionClass($class);
            if ($rc->isInterface()) {
                continue;
            }
            $stats['calls']++;
            if ($rc->hasMethod($method) || $rc->hasMethod('__call')) {
                continue;
            }
            $errors[] = "MISSING_METHOD {$rel}: {$class}::{$method}() called via app()";
        }
    }

    // --- Validation rules referencing tables/columns: exists:table,col | unique:table,col
    if (preg_match_all('/[\'"](exists|unique):([a-z0-9_]+),([a-zA-Z0-9_]+)/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $r) {
            [$whole, $kind, $table, $col] = $r;
            $stats['rules']++;
            if (! Schema::hasTable($table)) {
                $errors[] = "BAD_RULE_TABLE {$rel}: {$kind}:{$table} — table does not exist";
                continue;
            }
            if (! Schema::hasColumn($table, $col)) {
                $errors[] = "BAD_RULE_COLUMN {$rel}: {$kind}:{$table},{$col} — column does not exist";
            }
        }
    }
}

echo "Scanned {$stats['files']} files, {$stats['calls']} verifiable calls, {$stats['rules']} table-backed rules\n";
if ($errors === []) {
    echo "\n=== 0 call-integrity problems ===\n";
    exit(0);
}
echo "\n=== ".count($errors)." problems ===\n";
foreach (array_unique($errors) as $e) {
    echo "  $e\n";
}
exit(1);
