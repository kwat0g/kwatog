<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Common\Enums\ExportFormat;
use App\Common\Jobs\CreateBackupJob;
use App\Common\Jobs\RestoreBackupJob;
use App\Common\Mail\EmailIntegrationTestMail;
use App\Common\Mail\NotificationDigestMail;
use App\Common\Mail\ScheduledExportMail;
use App\Common\Mail\UserNotificationMail;
use App\Common\Services\BackupService;
use App\Modules\Accounting\Jobs\SyncBudgetActuals;
use App\Modules\Accounting\Listeners\RunBudgetActualsSyncOnRequested;
use App\Modules\Assets\Jobs\RunMonthlyDepreciationJob;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Mail\PortalPasswordResetMail;
use App\Modules\HR\Notifications\EmployeePasswordResetNotification;
use App\Modules\HR\Notifications\EmployeeWelcomeNotification;
use App\Modules\Leave\Jobs\ProcessYearEndLeave;
use App\Modules\Maintenance\Jobs\GeneratePreventiveMaintenanceJob;
use App\Modules\MRP\Jobs\RunAutomaticMrpJob;
use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use App\Modules\Payroll\Jobs\SendPayslipEmailJob;
use App\Modules\Payroll\Listeners\RunPayrollComputationOnRequested;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Drift guard for the queue lane contract (config/horizon.php).
 *
 * Before 2026-10 every lane was one `queue:work` process and the lane a job
 * declared was advisory: mail shared `default` with the listeners behind
 * approvals, and backup shared `low` with MRP and payroll. Two failure modes
 * were invisible in code review and only visible under load:
 *
 *   1. A job naming a lane with NO worker sits in Redis forever. That is what
 *      would happen to any job still saying `low` after the split.
 *   2. A supervisor whose `retry_after` is below its own `timeout` gets its
 *      job re-reserved mid-flight — two restores, two payroll runs. Horizon
 *      defaults that lease to 60s when a supervisor omits it, so silence is
 *      the dangerous case.
 *
 * Both are asserted here rather than trusted, exactly because neither shows up
 * as a symptom until production is busy.
 */
final class QueueLaneAssignmentTest extends TestCase
{
    /** Every queue name that has a Horizon supervisor behind it. */
    private const LANES = ['high', 'default', 'mail', 'automation', 'bulk'];

    public function test_every_lane_has_exactly_one_supervisor_and_a_lease_above_its_timeout(): void
    {
        $supervisors = config('horizon.defaults');

        $this->assertIsArray($supervisors);
        $this->assertNotEmpty($supervisors);

        $served = [];

        foreach ($supervisors as $name => $options) {
            $queues = (array) ($options['queue'] ?? []);

            $this->assertNotEmpty($queues, "Supervisor [{$name}] serves no queue.");

            foreach ($queues as $queue) {
                $this->assertContains(
                    $queue,
                    self::LANES,
                    "Supervisor [{$name}] serves [{$queue}], which is not a declared lane.",
                );

                $this->assertArrayNotHasKey(
                    $queue,
                    $served,
                    "Lane [{$queue}] is served by both [".($served[$queue] ?? '')."] and [{$name}]; one lane, one pool.",
                );

                $served[$queue] = $name;
            }

            $timeout = (int) ($options['timeout'] ?? 60);
            $lease = (int) ($options['retry_after'] ?? 0);

            $this->assertGreaterThan(0, $timeout, "Supervisor [{$name}] has no timeout.");
            $this->assertGreaterThan(
                $timeout,
                $lease,
                "Supervisor [{$name}] reserves jobs for {$lease}s but may run them for {$timeout}s: "
                .'Redis will hand the same job to a second worker while the first is still running.',
            );
        }

        // A declared lane with no worker is a queue nothing drains.
        foreach (self::LANES as $lane) {
            $this->assertArrayHasKey($lane, $served, "Lane [{$lane}] has no supervisor.");
        }
    }

    public function test_environment_overrides_only_touch_supervisors_that_exist(): void
    {
        $defined = array_keys((array) config('horizon.defaults'));

        foreach ((array) config('horizon.environments') as $environment => $overrides) {
            // Horizon merges environment overrides by supervisor key, so an
            // unknown key here is silently ignored — a scaling rule that never
            // applies. That is why the repo's own deployment checks it.
            foreach (array_keys((array) $overrides) as $supervisor) {
                $this->assertContains(
                    $supervisor,
                    $defined,
                    "horizon.environments.{$environment} configures [{$supervisor}], which is not a supervisor.",
                );
            }
        }
    }

    public function test_the_plain_queue_connection_lease_covers_the_longest_worker_timeout(): void
    {
        // config/queue.php's retry_after is per CONNECTION and applying even
        // when Horizon is not the runner (queue:work in a break-glass path).
        $lease = (int) config('queue.connections.redis.retry_after');

        $longest = 0;
        foreach ((array) config('horizon.defaults') as $options) {
            $longest = max($longest, (int) ($options['timeout'] ?? 0));
        }

        $this->assertGreaterThan(
            $longest,
            $lease,
            "queue.connections.redis.retry_after ({$lease}s) is below the longest worker timeout ({$longest}s).",
        );
    }

    public function test_every_queued_mailable_lands_on_the_mail_lane(): void
    {
        // Behavioural proof that the mechanism works, on a mailable whose
        // constructor needs no database records.
        $mailable = new PortalPasswordResetMail('supplier', 'token', 'Recipient');

        $this->assertSame('mail', $mailable->queue);

        // Structural sweep: mailables that need models cannot be built
        // here, but a new one that forgets the lane would land on `default` and
        // quietly compete with approvals again. Fail the file, not the instance.
        $files = collect(File::allFiles(app_path()))
            ->filter(fn (\SplFileInfo $file) => $file->getPathInfo()->getFilename() === 'Mail')
            ->filter(fn (\SplFileInfo $file) => str_contains($file->getContents(), 'implements ShouldQueue'));

        $this->assertGreaterThanOrEqual(25, $files->count(), 'Mailable discovery found fewer files than expected.');

        foreach ($files as $file) {
            $this->assertStringContainsString(
                "\$this->onQueue('mail');",
                $file->getContents(),
                basename($file->getPathname()).' is queued but does not set the mail lane; '
                .'it will land on `default` and compete with interactive listeners.',
            );
        }
    }

    public function test_common_queued_mailables_and_notifications_land_on_the_mail_lane(): void
    {
        $this->assertSame('mail', (new EmployeeWelcomeNotification('Temp-Passw0rd!'))->queue);
        $this->assertSame('mail', (new EmployeePasswordResetNotification('Temp-Passw0rd!'))->queue);
        $this->assertSame('mail', (new EmailIntegrationTestMail)->queue);
        $this->assertSame('mail', (new NotificationDigestMail(null, [], 0))->queue);
        $this->assertSame('mail', (new UserNotificationMail('test.type', ['title' => 'Test', 'message' => 'Test']))->queue);
        $this->assertSame('mail', (new ScheduledExportMail('Test', 'hr.employees', 'test.csv', 'test', ExportFormat::Csv))->queue);
    }

    public function test_automation_runs_on_the_automation_lane(): void
    {
        $this->assertSame('automation', (new RunAutomaticMrpJob([1], 'test'))->queue);
        $this->assertSame('automation', (new ProcessPayrollJob(new PayrollPeriod))->queue);
        $this->assertSame('automation', (new RunMonthlyDepreciationJob)->queue);
        $this->assertSame('automation', (new SyncBudgetActuals)->queue);
        $this->assertSame('automation', (new ProcessYearEndLeave(new User))->queue);
        $this->assertSame('automation', (new GeneratePreventiveMaintenanceJob)->queue);
    }

    public function test_automation_listeners_holding_the_work_inline_use_the_same_lane(): void
    {
        // These listeners run the computation themselves instead of
        // dispatching the job; if they and their job disagree, one path is
        // unprotected from the backup lane and the other is not.
        $this->assertSame('automation', (new RunPayrollComputationOnRequested)->viaQueue());
        $this->assertSame('automation', (new RunBudgetActualsSyncOnRequested)->viaQueue());
    }

    public function test_backup_and_restore_run_on_the_bulk_lane_and_never_retry(): void
    {
        $backup = new CreateBackupJob('op-1');
        $restore = new RestoreBackupJob('op-1');

        $this->assertSame('bulk', $backup->queue);
        $this->assertSame('bulk', $restore->queue);

        // A retried restore replays half-applied work; the lane must not do it.
        $this->assertSame(1, $restore->tries);
        $this->assertSame(1, $backup->tries);
    }

    public function test_restore_pauses_and_drains_non_bulk_lanes_then_resumes_them(): void
    {
        $environment = config('horizon.env') ?? config('app.env', 'production');
        $supervisors = array_replace_recursive(
            (array) config('horizon.defaults'),
            (array) config("horizon.environments.{$environment}", []),
        );
        $expectedTargets = collect($supervisors)
            ->flatMap(fn (array $supervisor): array => collect((array) ($supervisor['queue'] ?? []))
                ->reject(fn (string $queue): bool => $queue === 'bulk')
                ->map(fn (string $queue): array => [
                    'connection' => $supervisor['connection'] ?? 'redis',
                    'queue' => $queue,
                ])
                ->all())
            ->values()
            ->all();

        $polls = [];
        $reservedSize = function (string $queue) use (&$polls): int {
            $polls[$queue] = ($polls[$queue] ?? 0) + 1;

            return $queue === 'automation' && $polls[$queue] === 1 ? 1 : 0;
        };
        $redisQueue = new class($reservedSize)
        {
            public function __construct(private readonly \Closure $reservedSize) {}

            public function reservedSize(string $queue): int
            {
                return ($this->reservedSize)($queue);
            }
        };
        $queueManager = Mockery::mock();
        $queueManager->shouldReceive('connection')->with('redis')->once()->andReturn($redisQueue);
        foreach ($expectedTargets as $target) {
            $queueManager->shouldReceive('pauseFor')
                ->with($target['connection'], $target['queue'], config('backup.queue_pause_seconds', 21600))
                ->once();
            $queueManager->shouldReceive('resume')
                ->with($target['connection'], $target['queue'])
                ->once();
            $queueManager->shouldReceive('isPaused')
                ->with($target['connection'], $target['queue'])
                ->twice()
                ->andReturn(true, false);
        }
        Queue::swap($queueManager);
        config(['queue.connections.redis.block_for' => 1]);

        $service = app(BackupService::class);
        $pause = new \ReflectionMethod(BackupService::class, 'pauseQueues');
        $resume = new \ReflectionMethod(BackupService::class, 'resumeQueues');
        $targets = $pause->invoke($service);

        try {
            $expected = collect($expectedTargets)
                ->map(fn (array $target): string => $target['connection'].':'.$target['queue'])
                ->sort()
                ->values()
                ->all();
            $actual = collect($targets)
                ->map(fn (array $target): string => $target['connection'].':'.$target['queue'])
                ->sort()
                ->values()
                ->all();

            $this->assertSame($expected, $actual);
            $this->assertGreaterThan(1, $polls['automation']);
            foreach ($targets as $target) {
                $this->assertTrue(Queue::isPaused($target['connection'], $target['queue']));
            }
        } finally {
            $resume->invoke($service, $targets);
        }

        foreach ($targets as $target) {
            $this->assertFalse(Queue::isPaused($target['connection'], $target['queue']));
        }
    }

    public function test_payslip_fan_out_is_treated_as_mail(): void
    {
        $this->assertSame('mail', (new SendPayslipEmailJob(1))->queue);
    }

    public function test_no_producer_targets_a_lane_without_a_worker(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/onQueue\('([a-z_-]+)'\)/", $file->getContents(), $matches);

            foreach ($matches[1] as $lane) {
                if (! in_array($lane, self::LANES, true)) {
                    $offenders[] = $file->getRelativePathname()." → {$lane}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These producers name a lane with no Horizon supervisor, so the job would sit in Redis: '
            .implode(', ', $offenders),
        );
    }
}
