<?php

declare(strict_types=1);

/**
 * Write-path audit.
 *
 * Two bug classes that only fire when someone actually submits a form:
 *
 * 1. MASS_ASSIGNMENT — `Foo::create([... 'status' => ...])` where `status` was
 *    deliberately pulled out of `$fillable` (the hardening convention in
 *    CLAUDE.md). Laravel throws MassAssignmentException at runtime; nothing
 *    static catches it.
 *
 * 2. BAD_ENUM_LITERAL — a string literal written to a column the model casts to
 *    an enum, where the literal is not one of the enum's cases. PG rejects it
 *    via CHECK constraint, or the cast throws on the next read.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$errors = [];
$stats = ['files' => 0, 'creates' => 0, 'literals' => 0];

/** @return string[] */
function phpFilesIn(string $dir): array
{
    $out = [];
    if (! is_dir($dir)) {
        return $out;
    }
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($rii as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);

    return $out;
}

/**
 * Extract top-level `'key' =>` names from the array literal that starts at $start
 * (index of the opening bracket) — brace-depth aware so nested arrays are skipped.
 *
 * @return array{0: string[], 1: array<string, string>} [keys, key => literal value]
 */
function arrayKeysAt(string $src, int $start): array
{
    $len = strlen($src);
    $depth = 0;
    $keys = [];
    $vals = [];
    $i = $start;
    $buf = '';
    for (; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '[' || $ch === '(') {
            $depth++;
            if ($depth === 1) {
                $buf = '';
            }

            continue;
        }
        if ($ch === ']' || $ch === ')') {
            $depth--;
            if ($depth === 0) {
                break;
            }

            continue;
        }
        if ($depth === 1) {
            $buf .= $ch;
        }
    }
    // top-level pairs only
    if (preg_match_all('/[\'"]([a-zA-Z_][a-zA-Z0-9_]*)[\'"]\s*=>\s*([^,]*)/', $buf, $m, PREG_SET_ORDER)) {
        foreach ($m as $pair) {
            $keys[] = $pair[1];
            $vals[$pair[1]] = trim($pair[2]);
        }
    }

    return [$keys, $vals];
}

$files = array_merge(
    phpFilesIn(__DIR__.'/app'),
);

foreach ($files as $file) {
    $src = file_get_contents($file);
    if ($src === false) {
        continue;
    }
    $stats['files']++;
    $rel = str_replace(__DIR__.'/', '', $file);

    // alias map
    $aliases = [];
    if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?;/m', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $u) {
            $fqcn = $u[1];
            $aliases[$u[2] ?? substr($fqcn, (int) strrpos($fqcn, '\\') + 1)] = $fqcn;
        }
    }

    // Find `Model::create([` occurrences.
    if (! preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::create\s*\(\s*\[/', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        continue;
    }

    foreach ($m as $hit) {
        $short = $hit[1][0];
        $fqcn = $aliases[$short] ?? null;
        if ($fqcn === null || ! class_exists($fqcn) || ! is_subclass_of($fqcn, Model::class)) {
            continue;
        }

        $bracketPos = strpos($src, '[', $hit[0][1]);
        if ($bracketPos === false) {
            continue;
        }
        [$keys, $vals] = arrayKeysAt($src, $bracketPos);
        if ($keys === []) {
            continue;
        }
        $stats['creates']++;

        /** @var Model $proto */
        $proto = new $fqcn;
        $fillable = $proto->getFillable();
        $guarded = $proto->getGuarded();
        $line = substr_count(substr($src, 0, $hit[0][1]), "\n") + 1;

        // Only decidable when the model uses an explicit whitelist.
        if ($fillable !== []) {
            foreach ($keys as $k) {
                if (! in_array($k, $fillable, true)) {
                    $errors[] = "MASS_ASSIGNMENT {$rel}:{$line}: {$short}::create() passes '{$k}' — not in \$fillable";
                }
            }
        } elseif ($guarded !== [] && $guarded !== ['*']) {
            foreach ($keys as $k) {
                if (in_array($k, $guarded, true)) {
                    $errors[] = "MASS_ASSIGNMENT {$rel}:{$line}: {$short}::create() passes guarded '{$k}'";
                }
            }
        }

        // Enum literal check
        $casts = $proto->getCasts();
        foreach ($vals as $k => $v) {
            $cast = $casts[$k] ?? null;
            if (! is_string($cast) || ! enum_exists($cast)) {
                continue;
            }
            if (! preg_match('/^[\'"]([^\'"]*)[\'"]$/', $v, $lit)) {
                continue; // not a plain literal — skip
            }
            $stats['literals']++;
            $valid = array_map(fn ($c) => (string) ($c->value ?? $c->name), $cast::cases());
            if (! in_array($lit[1], $valid, true)) {
                $errors[] = "BAD_ENUM_LITERAL {$rel}:{$line}: {$short}::create() '{$k}' => '{$lit[1]}' — "
                    ."valid: ".implode('|', $valid);
            }
        }
    }
}

echo "Scanned {$stats['files']} files, {$stats['creates']} Model::create() calls, {$stats['literals']} enum literals\n";
if ($errors === []) {
    echo "\n=== 0 write-path problems ===\n";
    exit(0);
}
$errors = array_values(array_unique($errors));
echo "\n=== ".count($errors)." problems ===\n";
foreach ($errors as $e) {
    echo "  $e\n";
}
exit(1);
