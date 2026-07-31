<?php

declare(strict_types=1);

/**
 * Detail-route smoke for EMPTY tables.
 *
 * smoke_get.php skips any {model} route whose table has no rows — 27 routes.
 * Those are precisely the screens a tester reaches right after creating their
 * first record, so a 500 there is a live demo blowing up.
 *
 * Strategy: synthesize one minimal valid row per empty table by introspecting
 * the schema (NOT NULL + no default = must fill; FK = borrow an existing id;
 * enum-cast column = first enum case), hit the route, then roll everything back.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$httpKernel = $app->make(HttpKernel::class);
$app->make(ConsoleKernel::class)->bootstrap();

$admin = \App\Modules\Auth\Models\User::whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))->first();
if (! $admin) {
    fwrite(STDERR, "no admin user\n");
    exit(1);
}

// Throttling off so the sweep does not trip rate limits.
$noop = new class
{
    public function handle($r, $n, ...$a)
    {
        return $n($r);
    }
};
$app->instance(\Illuminate\Routing\Middleware\ThrottleRequests::class, $noop);
$app->instance(\Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class, $noop);

$router = $app['router'];

/** table -> [col => ['ref_table'=>..,'ref_col'=>..]] */
function foreignKeys(string $table): array
{
    $rows = DB::select(<<<'SQL'
        SELECT att.attname AS col, cl2.relname AS ref_table, att2.attname AS ref_col
        FROM pg_constraint c
        JOIN pg_class cl  ON cl.oid  = c.conrelid
        JOIN pg_class cl2 ON cl2.oid = c.confrelid
        JOIN unnest(c.conkey)  WITH ORDINALITY AS k(attnum, ord)  ON TRUE
        JOIN unnest(c.confkey) WITH ORDINALITY AS fk(attnum, ord) ON fk.ord = k.ord
        JOIN pg_attribute att  ON att.attrelid  = cl.oid  AND att.attnum  = k.attnum
        JOIN pg_attribute att2 ON att2.attrelid = cl2.oid AND att2.attnum = fk.attnum
        WHERE c.contype = 'f' AND cl.relname = ?
        SQL, [$table]);
    $out = [];
    foreach ($rows as $r) {
        $out[$r->col] = ['ref_table' => $r->ref_table, 'ref_col' => $r->ref_col];
    }

    return $out;
}

/** columns that MUST be supplied: NOT NULL, no default, not the PK */
function requiredColumns(string $table): array
{
    return DB::select(<<<'SQL'
        SELECT a.attname AS col, format_type(a.atttypid, a.atttypmod) AS type
        FROM pg_attribute a
        JOIN pg_class c ON c.oid = a.attrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE c.relname = ? AND n.nspname = current_schema()
          AND a.attnum > 0 AND NOT a.attisdropped
          AND a.attnotnull
          AND NOT EXISTS (SELECT 1 FROM pg_attrdef d WHERE d.adrelid = c.oid AND d.adnum = a.attnum)
        ORDER BY a.attnum
        SQL, [$table]);
}

/** enum values allowed by a CHECK constraint on this column, if any */
function checkEnumValues(string $table, string $col): array
{
    $rows = DB::select(<<<'SQL'
        SELECT pg_get_constraintdef(c.oid) AS def
        FROM pg_constraint c
        JOIN pg_class cl ON cl.oid = c.conrelid
        WHERE c.contype = 'c' AND cl.relname = ?
        SQL, [$table]);
    foreach ($rows as $r) {
        if (! str_contains($r->def, '"'.$col.'"') && ! str_contains($r->def, ' '.$col.' ')) {
            continue;
        }
        if (preg_match_all("/'([^']+)'::/", $r->def, $m)) {
            return $m[1];
        }
    }

    return [];
}

/** first value of the enum a model casts this column to */
function enumCastValue(string $modelClass, string $col): ?string
{
    try {
        $m = new $modelClass;
        $casts = $m->getCasts();
    } catch (\Throwable) {
        return null;
    }
    $cast = $casts[$col] ?? null;
    if (! is_string($cast) || ! enum_exists($cast)) {
        return null;
    }
    $cases = $cast::cases();

    return $cases === [] ? null : (string) ($cases[0]->value ?? $cases[0]->name);
}

$fkCache = [];
$borrowFk = function (string $refTable, string $refCol) use (&$fkCache) {
    $key = "$refTable.$refCol";
    if (array_key_exists($key, $fkCache)) {
        return $fkCache[$key];
    }

    return $fkCache[$key] = DB::table($refTable)->value($refCol);
};

/**
 * Build + insert a minimal row. Returns the new PK, or throws.
 */
$makeRow = function (string $modelClass) use ($borrowFk): int {
    /** @var Model $proto */
    $proto = new $modelClass;
    $table = $proto->getTable();
    $pk = $proto->getKeyName();
    $fks = foreignKeys($table);
    $data = [];

    foreach (requiredColumns($table) as $c) {
        $col = $c->col;
        $type = $c->type;
        if ($col === $pk) {
            continue;
        }

        if (isset($fks[$col])) {
            $v = $borrowFk($fks[$col]['ref_table'], $fks[$col]['ref_col']);
            if ($v === null) {
                throw new RuntimeException("FK {$col} -> {$fks[$col]['ref_table']} is empty");
            }
            $data[$col] = $v;
            continue;
        }

        $enum = enumCastValue($modelClass, $col) ?? (checkEnumValues($table, $col)[0] ?? null);
        if ($enum !== null) {
            $data[$col] = $enum;
            continue;
        }

        $data[$col] = match (true) {
            str_contains($type, 'timestamp'), str_contains($type, 'date') => now(),
            str_contains($type, 'boolean') => false,
            str_contains($type, 'json'), str_contains($type, 'jsonb') => '[]',
            str_contains($type, 'numeric'), str_contains($type, 'int'),
            str_contains($type, 'double'), str_contains($type, 'real') => 1,
            default => 'T'.substr((string) DB::table($table)->count(), 0, 3).'1',
        };
    }

    // Timestamps are usually nullable, but fill when present so accessors don't blow up.
    foreach (['created_at', 'updated_at'] as $ts) {
        if (Schema::hasColumn($table, $ts) && ! isset($data[$ts])) {
            $data[$ts] = now();
        }
    }

    return (int) DB::table($table)->insertGetId($data, $pk);
};

// ---- collect GET routes with model-bound params on empty tables ----------
$targets = [];
foreach ($router->getRoutes() as $route) {
    if (! in_array('GET', $route->methods(), true)) {
        continue;
    }
    $uri = $route->uri();
    if (! str_starts_with($uri, 'api/v1/')) {
        continue;
    }
    $action = $route->getActionName();
    if ($action === 'Closure' || ! str_contains($action, '@')) {
        continue;
    }
    [$class, $method] = explode('@', $action, 2);
    if (! class_exists($class) || ! method_exists($class, $method)) {
        continue;
    }

    // map param name -> model class from the controller signature
    $params = [];
    foreach ((new ReflectionMethod($class, $method))->getParameters() as $p) {
        $t = $p->getType();
        if ($t instanceof ReflectionNamedType && ! $t->isBuiltin()
            && is_subclass_of($t->getName(), Model::class)) {
            $params[$p->getName()] = $t->getName();
        }
    }

    preg_match_all('/\{(\w+)(\??)\}/', $uri, $m, PREG_SET_ORDER);
    $needed = [];
    $ok = true;
    foreach ($m as [, $name, $opt]) {
        if ($opt === '?') {
            continue;
        }
        $mc = $params[$name] ?? null;
        if ($mc === null) {
            $ok = false;
            break; // scalar param — cannot synthesize meaningfully
        }
        $needed[$name] = $mc;
    }
    if (! $ok || $needed === []) {
        continue;
    }

    // only the ones smoke_get could not cover: at least one empty table
    $hasEmpty = false;
    foreach ($needed as $mc) {
        try {
            if ((new $mc)->newQuery()->withoutGlobalScopes()->count() === 0) {
                $hasEmpty = true;
            }
        } catch (\Throwable) {
            $ok = false;
        }
    }
    if (! $ok || ! $hasEmpty) {
        continue;
    }

    $targets[] = [$uri, $needed];
}

echo 'Targets (GET routes blocked by empty tables): '.count($targets)."\n\n";

$results = [];
$unbuildable = [];

DB::beginTransaction();
try {
    foreach ($targets as [$uri, $needed]) {
        $path = $uri;
        $failed = null;
        foreach ($needed as $name => $modelClass) {
            try {
                $row = (new $modelClass)->newQuery()->withoutGlobalScopes()->first();
                if ($row === null) {
                    $id = $makeRow($modelClass);
                    $row = (new $modelClass)->newQuery()->withoutGlobalScopes()->find($id);
                }
                if ($row === null) {
                    throw new RuntimeException('row vanished after insert');
                }
                $path = str_replace('{'.$name.'}', $row->hash_id, $path);
            } catch (\Throwable $e) {
                $failed = "$modelClass: ".$e->getMessage();
                break;
            }
        }
        if ($failed !== null) {
            $unbuildable[] = ["GET /$uri", $failed];
            continue;
        }

        Auth::shouldUse('web');
        Auth::login($admin);
        $req = Request::create('/'.$path, 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $req->setUserResolver(fn () => $admin);
        $req->setLaravelSession($app['session']->driver());
        try {
            $resp = $httpKernel->handle($req);
            $status = $resp->getStatusCode();
            $body = (string) $resp->getContent();
        } catch (\Throwable $e) {
            $status = 599;
            $body = json_encode(['message' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()]);
        }
        if ($status >= 500) {
            $d = json_decode($body, true);
            $results[] = [$status, "GET /$uri", $d['message'] ?? substr(strip_tags($body), 0, 300),
                ($d['file'] ?? '').(isset($d['line']) ? ':'.$d['line'] : '')];
        } elseif ($status >= 400 && $status !== 404) {
            $d = json_decode($body, true);
            $results[] = [$status, "GET /$uri", $d['message'] ?? '', ''];
        }
        unset($resp);
    }
} finally {
    DB::rollBack();
}

echo '=== NON-OK ('.count($results).") ===\n";
usort($results, fn ($a, $b) => $b[0] <=> $a[0]);
foreach ($results as [$s, $r, $msg, $file]) {
    echo str_pad((string) $s, 4)." $r\n      $msg\n".($file ? "      @ $file\n" : '');
}

echo "\n=== COULD NOT SYNTHESIZE ROW (".count($unbuildable).") ===\n";
foreach ($unbuildable as [$r, $why]) {
    echo "  $r   [$why]\n";
}
