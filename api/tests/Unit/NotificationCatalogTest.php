<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\NotificationCatalog;
use App\Common\Services\SettingsService;
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
        ] as $type) {
            $this->assertArrayHasKey($type, $catalog, "Missing catalog entry for {$type}");
        }
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
            $relative = str_replace($appPath.'/', '', $file->getPathname());

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
