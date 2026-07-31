<?php

declare(strict_types=1);

/**
 * Param-aware smoke for query-param endpoints.
 *
 * smoke_get.php calls every GET with NO query string, so 18 endpoints answered
 * 422 at the validator and their SQL never ran. Those are analytics/report
 * endpoints — heavy selectRaw + GROUP BY — exactly where PG throws
 * (42803 grouping, 42P08 ambiguous type, 42702 ambiguous column) at runtime.
 *
 * Here each one is called with the SAME params the SPA sends, so the real query
 * executes. Params are taken from the SPA api/*.ts call sites.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$httpKernel = $app->make(HttpKernel::class);
$app->make(ConsoleKernel::class)->bootstrap();

$admin = \App\Modules\Auth\Models\User::whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))->first();
if (! $admin) {
    fwrite(STDERR, "no admin user\n");
    exit(1);
}

$noop = new class
{
    public function handle($r, $n, ...$a)
    {
        return $n($r);
    }
};
$app->instance(\Illuminate\Routing\Middleware\ThrottleRequests::class, $noop);
$app->instance(\Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class, $noop);

/** hash id of the first row of a model, or null */
$hid = function (string $modelClass): ?string {
    try {
        $row = (new $modelClass)->newQuery()->withoutGlobalScopes()->first();

        return $row?->hash_id;
    } catch (\Throwable) {
        return null;
    }
};
/** raw first id */
$rid = function (string $table, string $col = 'id') {
    return DB::table($table)->value($col);
};

$from = now()->subYear()->toDateString();
$to = now()->addMonth()->toDateString();
$monthStart = now()->startOfMonth()->toDateString();
$monthEnd = now()->endOfMonth()->toDateString();

$roleA = $rid('roles', 'id');
$roleB = DB::table('roles')->where('id', '!=', $roleA)->value('id');
$roleAh = $roleA ? app('hashids')->encode($roleA) : null;
$roleBh = $roleB ? app('hashids')->encode($roleB) : null;

$employeeH = $hid(\App\Modules\HR\Models\Employee::class);
$machineH = $hid(\App\Modules\MRP\Models\Machine::class);
$deptH = $hid(\App\Modules\HR\Models\Department::class);
$itemH = $hid(\App\Modules\Inventory\Models\Item::class);
$productH = $hid(\App\Modules\CRM\Models\Product::class);
$periodH = $hid(\App\Modules\Payroll\Models\PayrollPeriod::class);
$period2 = null;
try {
    $ps = \App\Modules\Payroll\Models\PayrollPeriod::query()->orderBy('id')->take(2)->get();
    $period2 = $ps->count() > 1 ? $ps[1]->hash_id : ($ps[0]->hash_id ?? null);
} catch (\Throwable) {
}
$fyH = $hid(\App\Modules\Accounting\Models\FiscalYear::class);
$inspectionOutgoing = null;
try {
    $inspectionOutgoing = \App\Modules\Quality\Models\Inspection::query()
        ->where('stage', 'outgoing')->first()?->hash_id;
} catch (\Throwable) {
}

/**
 * uri => query params. {X} placeholders substituted below.
 * Mirrors the SPA call sites in spa/src/api/**.
 */
$cases = [
    ['api/v1/admin/roles/compare', ['a' => $roleAh, 'b' => $roleBh]],
    ['api/v1/admin/audit-logs/entity', ['model_type' => 'employees', 'model_id' => $employeeH]],
    ['api/v1/search', ['q' => 'og']],
    ['api/v1/admin/gov-tables', ['agency' => 'sss']],
    ['api/v1/loans/limits/{employee}', ['loan_type' => 'company_loan'], \App\Modules\HR\Models\Employee::class],
    ['api/v1/budgets/check-availability', ['department_id' => $deptH, 'amount' => '100.00', 'fiscal_year_id' => $fyH]],
    ['api/v1/production/operations/schedule', ['from' => $from, 'to' => $to]],
    ['api/v1/quality/inspections/aql-preview', ['batch_quantity' => 500]],
    ['api/v1/quality/analytics/defect-pareto/drill', ['parameter_name' => 'Outer Diameter', 'from' => $from, 'to' => $to]],
    ['api/v1/maintenance/condition-readings', ['machine_id' => $machineH]],
    ['api/v1/maintenance/condition-readings/trend', ['machine_id' => $machineH, 'metric' => 'vibration', 'from' => $from, 'to' => $to]],
    ['api/v1/maintenance/condition-readings/health-snapshot', ['machine_id' => $machineH]],
    ['api/v1/forecasting/demand-forecasts/historical', ['product_id' => $productH, 'months' => 12]],
    ['api/v1/forecasting/mrp-projection', ['year' => (int) now()->year, 'month' => (int) now()->month]],
    ['api/v1/calendar/events', ['from' => $monthStart, 'to' => $monthEnd]],
    ['api/v1/payroll-periods/{period}/variance', ['compare_to' => $period2], \App\Modules\Payroll\Models\PayrollPeriod::class],
    ['api/v1/quality/inspections/{inspection}/coc', [], \App\Modules\Quality\Models\Inspection::class, $inspectionOutgoing],
    // extra analytics/report endpoints worth exercising with explicit ranges
    ['api/v1/quality/analytics/defect-pareto', ['from' => $from, 'to' => $to]],
    ['api/v1/dashboard/kpi/trend/{code}', ['months' => 6], null, 'oee'],
    ['api/v1/exports/hr.employees/columns', []],
    ['api/v1/exports/hr.employees/preview', ['limit' => 5]],
    ['api/v1/hr/self-service/documents/contributions/{type}', [], null, 'sss'],
];

$router = $app['router'];
$results = [];
$skipped = [];
$ran = 0;

foreach ($cases as $case) {
    $uri = $case[0];
    $params = array_filter($case[1], fn ($v) => $v !== null && $v !== '');
    $bindClass = $case[2] ?? null;
    $explicitId = $case[3] ?? null;

    // resolve {param} in the uri
    if (preg_match('/\{(\w+)\??\}/', $uri, $m)) {
        $val = $explicitId;
        if ($val === null && $bindClass !== null) {
            $val = $hid($bindClass);
        }
        if ($val === null) {
            $skipped[] = ["GET /$uri", 'no row / no id for {'.$m[1].'}'];
            continue;
        }
        $uri = preg_replace('/\{\w+\??\}/', (string) $val, $uri, 1);
    }

    // required params missing => cannot exercise
    $missing = [];
    foreach ($case[1] as $k => $v) {
        if ($v === null || $v === '') {
            $missing[] = $k;
        }
    }
    if ($missing !== []) {
        $skipped[] = ["GET /$uri", 'no data for params: '.implode(',', $missing)];
        continue;
    }

    $ran++;
    Auth::shouldUse('web');
    Auth::login($admin);
    $req = Request::create('/'.$uri, 'GET', $params, [], [], [
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

    $q = http_build_query($params);
    $label = "GET /$uri".($q ? "?$q" : '');
    if ($status >= 400) {
        $d = json_decode($body, true);
        $results[] = [$status, $label, $d['message'] ?? substr(strip_tags($body), 0, 300),
            ($d['file'] ?? '').(isset($d['line']) ? ':'.$d['line'] : '')];
    } else {
        $results[] = [$status, $label, '', ''];
    }
    unset($resp);
}

echo "Exercised {$ran} param-backed endpoints\n\n";
usort($results, fn ($a, $b) => $b[0] <=> $a[0]);
$bad = 0;
foreach ($results as [$s, $r, $msg, $file]) {
    if ($s >= 400) {
        $bad++;
    }
    echo str_pad((string) $s, 4)." $r\n".($msg ? "      $msg\n" : '').($file ? "      @ $file\n" : '');
}
echo "\n=== ".$bad." non-2xx ===\n";
echo "\n=== SKIPPED (".count($skipped).") ===\n";
foreach ($skipped as [$r, $why]) {
    echo "  $r   [$why]\n";
}
