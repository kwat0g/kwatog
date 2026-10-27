<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Models\Alert;
use App\Common\Notifications\CriticalAlertEmail;
use App\Common\Services\NotificationCatalog;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Notifications\PasswordResetLinkNotification;
use App\Modules\Auth\Notifications\PasswordResetNotification;
use App\Modules\Auth\Notifications\WelcomeNotification;
use App\Modules\HR\Notifications\EmployeeWelcomeNotification;
use App\Modules\Production\Notifications\DailyProductionSummary;
use App\Modules\Production\Notifications\WeeklyProductionSummary;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

/**
 * The preferences page can only switch off types the catalog knows about, so a
 * type that fires in production but is missing from the catalog is a
 * notification the user is forced to receive forever.
 *
 * That is exactly what happened: `notifications.catalog` was seeded as a
 * frozen snapshot in migration 0412, and every type shipped afterwards
 * (quality escalations, dunning, training expiry, …) became unmutable. These
 * tests lock both halves of the fix — the code-level default list stays
 * complete, and a stale stored snapshot cannot hide new types.
 */
class NotificationCatalogTest extends TestCase
{
    /**
     * Every `NotificationService::send()` / `notify()` call site in the
     * codebase, scraped from source rather than hand-listed so a new caller
     * cannot be added without either appearing here or failing this test.
     *
     * @return array<int, string>
     */
    private function emittedTypes(): array
    {
        $appPath = dirname(__DIR__, 2).'/app';
        $types   = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // ->send($recipients, 'type.key', [   and   ->notify($r, $n, 'type.key')
            preg_match_all(
                '/->(?:send|notify)\(\s*[^,;()]+(?:,\s*\$[a-zA-Z_>-]+)?\s*,\s*\'([a-z0-9_.]+)\'/',
                $source,
                $matches,
            );

            foreach ($matches[1] as $type) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    public function test_every_emitted_notification_type_is_in_the_catalog(): void
    {
        $emitted = $this->emittedTypes();

        // Guard the scraper itself: if the regex stops matching, the test must
        // fail loudly rather than silently pass on an empty set.
        $this->assertGreaterThan(
            25,
            count($emitted),
            'Scraper found suspiciously few send() call sites — the pattern probably broke.',
        );

        $catalog = array_flip((new NotificationCatalog())->typeKeys());
        $missing = array_values(array_filter(
            $emitted,
            static fn (string $type): bool => ! isset($catalog[$type]),
        ));

        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "These notification types fire in code but users cannot mute them.\n".
            "Add them to NotificationCatalog::defaults():\n  %s",
            implode("\n  ", $missing),
        ));
    }

    public function test_dynamic_decision_types_are_in_the_catalog(): void
    {
        // Built at runtime (`$event->approved ? 'x_approved' : 'x_rejected'`),
        // so the source scrape above cannot see them.
        $catalog = array_flip((new NotificationCatalog())->typeKeys());

        foreach ([
            'loans.approved',
            'loans.rejected',
            'attendance.ot_approved',
            'attendance.ot_rejected',
            'production.rework.required',
            'production.rework.qc_failed',
            'production.rework.awaiting_qc',
            'production.rework.closed',
        ] as $type) {
            $this->assertArrayHasKey($type, $catalog, "Missing catalog entry for {$type}");
        }
    }

    public function test_horizon_queue_alert_is_in_the_notification_catalog(): void
    {
        $this->mock(SettingsService::class, function ($mock): void {
            $mock->shouldReceive('get')
                ->with('notifications.catalog')
                ->andReturn(null);
        });

        $this->assertContains('queue.long_wait', (new NotificationCatalog)->typeKeys());
    }

    public function test_stale_stored_catalog_is_backfilled_with_new_defaults(): void
    {
        // Simulates the real failure: a snapshot saved before newer types existed.
        $this->mock(SettingsService::class, function ($mock): void {
            $mock->shouldReceive('get')
                ->with('notifications.catalog')
                ->andReturn([
                    ['title' => 'Chain 1 · Order to Cash', 'hint' => 'stale', 'types' => [
                        ['key' => 'chain.so_confirmed', 'label' => 'Sales order confirmed', 'description' => 'x'],
                    ]],
                ]);
        });

        $keys = (new NotificationCatalog())->typeKeys();

        $this->assertContains('chain.so_confirmed', $keys);
        $this->assertContains('ncr.escalation', $keys, 'Newer default types must be backfilled into a stale snapshot.');
        $this->assertSame(
            count(array_unique($keys)),
            count($keys),
            'Backfill must not duplicate a type the snapshot already had.',
        );
    }

    public function test_admin_labels_and_ordering_win_over_defaults(): void
    {
        $this->mock(SettingsService::class, function ($mock): void {
            $mock->shouldReceive('get')
                ->with('notifications.catalog')
                ->andReturn([
                    ['title' => 'Chain 1 · Order to Cash', 'hint' => 'custom hint', 'types' => [
                        ['key' => 'chain.so_confirmed', 'label' => 'RENAMED BY ADMIN', 'description' => 'custom'],
                    ]],
                ]);
        });

        $groups = (new NotificationCatalog())->groups();

        $this->assertSame('Chain 1 · Order to Cash', $groups[0]['title'], 'Configured group must stay first.');
        $this->assertSame('custom hint', $groups[0]['hint']);
        $this->assertSame('RENAMED BY ADMIN', $groups[0]['types'][0]['label'], 'Admin wording must not be reverted.');
    }

    public function test_malformed_stored_catalog_is_normalised_before_backfill(): void
    {
        $this->mock(SettingsService::class, function ($mock): void {
            $mock->shouldReceive('get')
                ->with('notifications.catalog')
                ->andReturn([
                    ['title' => 'Broken group', 'hint' => null, 'types' => 'not-an-array'],
                    ['title' => 'Partial group', 'types' => [['key' => 'custom.type', 'label' => null]]],
                    'not-a-group',
                ]);
        });

        $groups = (new NotificationCatalog())->groups();

        $this->assertNotEmpty($groups);
        foreach ($groups as $group) {
            $this->assertIsString($group['title']);
            $this->assertIsString($group['hint']);
            $this->assertIsArray($group['types']);
        }

        $keys = (new NotificationCatalog())->typeKeys();
        $this->assertContains('custom.type', $keys);
        $this->assertContains('ncr.escalation', $keys);
    }

    public function test_no_code_path_writes_notifications_outside_the_service(): void
    {
        // Seven call sites used to write `$user->notifications()->create([...])`
        // directly. That skipped the preference check (an unmutable
        // notification), skipped UserNotificationCreated (no realtime toast),
        // and used a `link` key the SPA does not read instead of `link_to`, so
        // the rows were not clickable. They also never reached the catalog
        // scrape above, which is how six types stayed invisible.
        $appPath  = dirname(__DIR__, 2).'/app';
        $offences = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source   = (string) file_get_contents($file->getPathname());
            $normalizedAppPath = str_replace('\\', '/', $appPath);
            $relative = str_replace($normalizedAppPath.'/', '', str_replace('\\', '/', $file->getPathname()));

            if (preg_match('/->notifications\(\)\s*->\s*create\(/', $source)) {
                $offences[] = $relative.' — use NotificationService::send()';
            }

            // Raw inserts into the table, excluding the service that owns it.
            if ($relative !== 'Common/Services/NotificationService.php'
                && preg_match('/DB::table\(\s*\'notifications\'\s*\)\s*->\s*insert\(/', $source)) {
                $offences[] = $relative.' — raw insert into notifications';
            }
        }

        sort($offences);

        $this->assertSame([], $offences, sprintf(
            "Notifications must be written through NotificationService::send().\n  %s",
            implode("\n  ", $offences),
        ));
    }

    public function test_laravel_notification_dispatch_is_centralized_and_mail_only_classes_stay_mail_only(): void
    {
        $appPath = dirname(__DIR__, 2).'/app';
        $offences = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $relative = str_replace(
                str_replace('\\', '/', $appPath).'/',
                '',
                str_replace('\\', '/', $file->getPathname()),
            );
            if ($relative === 'Common/Services/NotificationService.php') {
                continue;
            }

            if (preg_match('/->notify\(\s*new\s+/', $source)
                || preg_match('/\bNotification(?:Facade)?::send(?:Now)?\s*\(/', $source)) {
                $offences[] = $relative.' — use NotificationService::sendLaravelNotification()';
            }
        }

        sort($offences);
        $this->assertSame([], $offences, sprintf(
            "Laravel Notification dispatches must use NotificationService.\n  %s",
            implode("\n  ", $offences),
        ));
    }

    public function test_native_laravel_notification_dispatch_is_limited_to_mail_only_flows(): void
    {
        $appPath = dirname(__DIR__, 2).'/app';
        $nativeCalls = [];
        $objectNotifyCalls = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $relative = str_replace(
                str_replace('\\', '/', $appPath).'/',
                '',
                str_replace('\\', '/', $file->getPathname()),
            );
            if (preg_match_all('/->notify\s*\(/', $source, $matches) > 0) {
                $objectNotifyCalls[$relative] = count($matches[0]);
            }
            if ($relative === 'Common/Services/NotificationService.php') {
                continue;
            }
            $calls = [];
            if (preg_match('/->notify\(\s*new\s+/', $source)) {
                $calls[] = 'user.notify';
            }
            if (preg_match('/->notify\(\s*\$notification\b/', $source)) {
                $calls[] = 'user.notify.variable';
            }
            if (preg_match('/\bNotification(?:Facade)?::(?:send|sendNow)\s*\(/', $source)) {
                $calls[] = 'Notification.send';
            }
            if (preg_match('/\bNotification::route\s*\(/', $source)) {
                $calls[] = 'Notification.route';
            }
            if ($calls !== []) {
                sort($calls);
                $nativeCalls[$relative] = $calls;
            }
        }

        ksort($nativeCalls);
        ksort($objectNotifyCalls);
        $notificationService = (string) file_get_contents($appPath.'/Common/Services/NotificationService.php');
        $this->assertDoesNotMatchRegularExpression('/public function notify\s*\(/', $notificationService);
        $this->assertSame([
            'Common/Services/AlertEngineService.php' => 1,
            'Common/Services/EmailDeliveryFailureNotifier.php' => 2,
            'Console/Commands/RunDueScheduledExports.php' => 1,
            'Console/Commands/SendDailyProductionSummary.php' => 2,
            'Console/Commands/SendWeeklyProductionSummary.php' => 2,
            'Modules/CRM/Services/PricingReviewService.php' => 7,
            'Modules/HR/Services/RecruitmentService.php' => 4,
            'Modules/HR/Services/TrainingExpiryService.php' => 1,
            'Modules/Production/Observers/ProductionStaffingObserver.php' => 2,
            'Modules/Purchasing/Services/SupplierResponseService.php' => 1,
            'Modules/Quality/Jobs/ProcessNcrRecurrenceScan.php' => 1,
            'Modules/ReturnManagement/Services/ReturnCaseService.php' => 6,
        ], $objectNotifyCalls, 'Any new object-level notify call must be reviewed and inventoried; use NotificationService::send() for in-app notices.');
        $this->assertSame([], $nativeCalls, sprintf(
            "Laravel Notification dispatches must use NotificationService::sendLaravelNotification().\n  %s",
            implode("\n  ", array_map(
                static fn (string $path, array $calls): string => $path.' ('.implode(', ', $calls).')',
                array_keys($nativeCalls),
                array_values($nativeCalls),
            )),
        ));

        $mailOnlyNotifications = [
            new WelcomeNotification('https://example.test/setup', 60),
            new EmployeeWelcomeNotification('https://example.test/setup', 60),
            new PasswordResetNotification('https://example.test/reset', 60),
            new PasswordResetLinkNotification('https://example.test/reset', 60),
            new DailyProductionSummary([]),
            new WeeklyProductionSummary([]),
            new CriticalAlertEmail(new Alert),
        ];
        foreach ($mailOnlyNotifications as $notification) {
            $this->assertInstanceOf(Notification::class, $notification);
            $this->assertSame(['mail'], $notification->via(new \stdClass), $notification::class.' must remain mail-only.');
        }
    }

    public function test_catalog_has_no_duplicate_keys(): void
    {
        $keys = [];

        foreach (NotificationCatalog::defaults() as $group) {
            foreach ($group['types'] as $type) {
                $keys[] = $type['key'];
            }
        }

        $duplicates = array_keys(array_filter(array_count_values($keys), static fn (int $n): bool => $n > 1));

        $this->assertSame([], $duplicates, 'Duplicate keys render twice in the preferences table.');
    }
}
