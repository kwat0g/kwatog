<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Horizon — one supervisor per LANE, not one big pool
|--------------------------------------------------------------------------
|
| The ERP's queued work falls into five classes that must not share a
| process (2026-10 queue review). The lane is the contract; a job picks its
| lane in code and this file decides how many processes serve it.
|
|   high        realtime bus: outbox dispatch, anything a request awaits
|   default     interactive listeners (approvals, escalations, GL posting)
|   mail        every outbound mail and mail notification
|   automation  MRP, payroll, depreciation, year-end, budget sync, PM
|   bulk        backup, restore, exports — the only lane allowed to run long
|
| Why lanes rather than one pool with priorities (the pre-Horizon setup):
|
|   1. `queue:work --queue=high,default` is serial. A single worker can only
|      run one job, so priority decides *what it picks next*, not what it
|      interrupts. A 15s listener delayed every `high` job by 15s.
|   2. Mail shared `default` with business listeners. 21 mailables at ~450ms
|      each is ~90s of lane occupancy per burst, in front of an approval.
|   3. Backup (timeout 3600) shared `low` with MRP and payroll — a restore
|      head-of-line blocked automation for up to an hour.
|
| `retry_after` is a LEASE, per connection, and Horizon defaults it to 60s
| when a supervisor omits it (RedisConnector). It MUST exceed that
| supervisor's `timeout`, or the job is re-reserved while the first copy is
| still running — two payrolls, two restores. Every lane below states both,
| and QueueLaneAssignmentTest asserts the invariant so it cannot drift.
|
*/

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    // `web` is the Sanctum SPA session guard; HorizonServiceProvider's gate
    // then restricts the dashboard to system_admin. There is no Bearer path.
    'middleware' => ['web'],

    /*
    | Long-wait thresholds (seconds). Horizon raises LongWaitDetected here;
    | active system administrators receive a deduplicated in-app alert.
    */
    'waits' => [
        'redis:high' => 15,
        'redis:default' => 60,
        'redis:mail' => 120,
        'redis:automation' => 180,
        'redis:bulk' => 900,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
        // DispatchOutboxMessage is the bus itself: every domain event produces
        // one, so it drowns the useful entries in the completed list.
        App\Common\Jobs\DispatchOutboxMessage::class,
    ],

    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 128,

    'defaults' => [

        // Realtime bus. Small timeout because nothing here is allowed to be slow.
        'supervisor-high' => [
            'connection' => 'redis',
            'queue' => ['high'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'retry_after' => 300,
            'nice' => 0,
        ],

        // Interactive listeners. Two warm processes so one slow listener can
        // never serialise an approval behind it.
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 2,
            'maxProcesses' => 8,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 300,
            'retry_after' => 900,
            'nice' => 0,
        ],

        // Outbound mail only. Isolated so a 200-recipient invoice run cannot
        // sit in front of the warehouse's GRN notification, and vice versa.
        'supervisor-mail' => [
            'connection' => 'redis',
            'queue' => ['mail'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 2,
            'maxProcesses' => 6,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'retry_after' => 300,
            'nice' => 0,
        ],

        // Scheduled business automation. Long jobs live here by design
        // (payroll computation), so the lease is generous — but it still
        // exceeds the timeout, which is the whole point.
        'supervisor-automation' => [
            'connection' => 'redis',
            'queue' => ['automation'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 5,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 1800,
            'retry_after' => 2100,
            'nice' => 0,
        ],

        // Backup / restore / exports. Single process, no autoscale: these are
        // the only jobs that may run for an hour, and nothing should queue
        // behind them. tries=1 — a failed restore must never be silently
        // replayed on top of its own partial work.
        'supervisor-bulk' => [
            'connection' => 'redis',
            'queue' => ['bulk'],
            'balance' => false,
            'processes' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            'timeout' => 3600,
            'retry_after' => 3900,
            'nice' => 0,
        ],
    ],

    'environments' => [

        'production' => [
            'supervisor-high' => ['maxProcesses' => 6],
            'supervisor-default' => ['minProcesses' => 2, 'maxProcesses' => 12],
            'supervisor-mail' => ['minProcesses' => 2, 'maxProcesses' => 10],
            'supervisor-automation' => ['maxProcesses' => 3],
        ],

        'local' => [
            'supervisor-high' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-default' => ['minProcesses' => 2, 'maxProcesses' => 4],
            'supervisor-mail' => ['minProcesses' => 2, 'maxProcesses' => 4],
            'supervisor-automation' => ['maxProcesses' => 2],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
