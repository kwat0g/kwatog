<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$errors = [];
$modelFiles = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/app'));
foreach ($rii as $f) {
    if (! $f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    if (! str_contains($f->getPathname(), '/Models/')) {
        continue;
    }
    $modelFiles[] = $f->getPathname();
}
$checked = 0;
foreach ($modelFiles as $file) {
    $src = file_get_contents($file);
    $tokens = token_get_all($src);
    $namespace = '';
    $className = null;
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (! is_array($tokens[$i])) {
            continue;
        }
        if ($tokens[$i][0] === T_NAMESPACE) {
            for ($i++; $i < $count; $i++) {
                if ($tokens[$i] === ';' || $tokens[$i] === '{') {
                    break;
                }
                if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $namespace .= $tokens[$i][1];
                }
            }
        }
        if ($tokens[$i][0] === T_CLASS) {
            // Ignore anonymous classes; model files are expected to declare one named class.
            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && $previous[0] === T_NEW) {
                continue;
            }
            for ($i++; $i < $count; $i++) {
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
                    $className = $tokens[$i][1];
                    break 2;
                }
            }
        }
    }
    if ($namespace === '' || $className === null) {
        continue;
    }
    $class = $namespace.'\\'.$className;
    if (! class_exists($class)) {
        $errors[] = ['CLASS_NOT_LOADABLE', $class, basename($file)];

        continue;
    }
    $r = new ReflectionClass($class);
    if ($r->isAbstract() || ! $r->isSubclassOf(Model::class)) {
        continue;
    }
    $m = new $class;
    $table = $m->getTable();
    if (! Schema::hasTable($table)) {
        $errors[] = ['MISSING_TABLE', $class, "table '$table' does not exist"];

        continue;
    }
    $checked++;
    $cols = array_flip(Schema::getColumnListing($table));

    foreach ($m->getFillable() as $col) {
        if (! isset($cols[$col])) {
            $errors[] = ['FILLABLE_NO_COLUMN', $class, "\$fillable '$col' missing from '$table'"];
        }
    }
    foreach (array_keys($m->getCasts()) as $col) {
        if ($col === $m->getKeyName()) {
            continue;
        }
        if (str_contains($col, '->')) {
            continue;
        }
        if (! isset($cols[$col])) {
            $errors[] = ['CAST_NO_COLUMN', $class, "\$casts '$col' missing from '$table'"];
        }
    }
    foreach ($m->getHidden() as $col) {
        if (! isset($cols[$col])) {
            $errors[] = ['HIDDEN_NO_COLUMN', $class, "\$hidden '$col' missing from '$table'"];
        }
    }
    // soft deletes need deleted_at
    if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
        if (! isset($cols[$m->getDeletedAtColumn()])) {
            $errors[] = ['SOFTDELETE_NO_COLUMN', $class, "SoftDeletes but '{$m->getDeletedAtColumn()}' missing from '$table'"];
        }
    }
    // timestamps
    if ($m->usesTimestamps()) {
        foreach ([$m->getCreatedAtColumn(), $m->getUpdatedAtColumn()] as $tc) {
            if ($tc && ! isset($cols[$tc])) {
                $errors[] = ['TIMESTAMP_NO_COLUMN', $class, "\$timestamps=true but '$tc' missing from '$table'"];
            }
        }
    }
}
foreach ($errors as [$t,$c,$msg]) {
    echo str_pad($t, 22)." | $c\n    -> $msg\n";
}
echo "\n=== ".count($errors)." model/schema problems across $checked models ===\n";
exit($errors === [] ? 0 : 1);
