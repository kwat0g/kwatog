<?php

declare(strict_types=1);

$root = is_dir(__DIR__.'/../api') ? __DIR__.'/../api' : __DIR__.'/..';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'ogami_test_audit_20261002') {
    throw new RuntimeException('Concurrency proof requires the isolated audit database.');
}
$fixture = json_decode(file_get_contents(storage_path('app/wo-crew-e2e.json')), true, flags: JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? '';
if ($mode === 'verify') {
    $ids = array_map(fn ($hash) => App\Modules\Production\Models\WorkOrder::decodeHash($hash), [$fixture['wo'], $fixture['overlap_wo']]);
    $count = App\Modules\Production\Models\WorkOrderCrew::whereIn('work_order_id', $ids)->whereNull('ended_at')->count();
    if ($count !== 1) { throw new RuntimeException('Exactly one competing crew must be committed; found '.$count); }
    echo "PASS: separate processes committed exactly one overlapping worker assignment.\n";
    exit;
}
$wo = App\Modules\Production\Models\WorkOrder::whereKey(App\Modules\Production\Models\WorkOrder::decodeHash($fixture[$mode === 'first' ? 'wo' : 'overlap_wo']))->firstOrFail();
$actor = App\Modules\Auth\Models\User::whereKey(App\Modules\Auth\Models\User::decodeHash($fixture['accounts']['ppc']['user_id']))->firstOrFail();
$workers = array_map(fn ($name) => App\Modules\HR\Models\Employee::decodeHash($fixture['accounts'][$name]['employee_id']), ['recorder', 'support']);
try {
    app(App\Modules\Production\Services\WorkOrderAssignmentService::class)->assign($wo, [
        'employee_ids' => $workers, 'recorder_employee_id' => $workers[0], 'minimum_workers' => 2,
        'maximum_workers' => 3, 'expected_crew_id' => null, 'reason' => 'Separate-process overlap acceptance.',
    ], $actor);
    echo "COMMITTED\n";
} catch (App\Common\Exceptions\BusinessRuleException $error) {
    if (! str_contains($error->getMessage(), 'overlaps')) { throw $error; }
    echo "BLOCKED: overlapping worker reservation\n";
}
