<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchiveDssRecommendationEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-23 12:00:00 UTC');
        config(['dss.event_retention_approval_reference' => null]);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_previews_without_writing(): void
    {
        $recommendationId = $this->recommendation();
        $this->event($recommendationId, '2024-10-15 00:00:00');
        $this->event($recommendationId, '2026-10-01 00:00:00');

        $this->artisan('dss:archive-events --dry-run')
            ->expectsOutputToContain('1 eligible event(s)')
            ->assertSuccessful();

        $this->assertSame(2, DB::table('dss_recommendation_events')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_archive_retry_and_corrupt_replacement_preserve_source_on_private_disk(): void
    {
        $recommendationId = $this->recommendation();
        $this->event($recommendationId, '2024-10-15 00:00:00', 'restricted reason');
        $disk = Storage::disk('local');
        $path = 'dss-event-archives/dss-events-2024-10.json';

        $this->artisan('dss:archive-events')->assertSuccessful();
        $firstArchive = $disk->get($path);
        $this->artisan('dss:archive-events')->assertSuccessful();
        $this->assertSame($firstArchive, $disk->get($path));
        $this->assertSame(1, json_decode($firstArchive, true, 512, JSON_THROW_ON_ERROR)['row_count']);
        $this->assertStringContainsString('restricted reason', $firstArchive);
        $this->assertSame(1, DB::table('dss_recommendation_events')->count());
        $this->assertFalse(str_starts_with($disk->path($path), public_path()));

        $disk->put($path, '{corrupt');
        $this->artisan('dss:archive-events')->assertSuccessful();
        $this->assertSame($firstArchive, $disk->get($path));
        $this->assertSame(1, DB::table('dss_recommendation_events')->count());
    }

    public function test_verified_archive_is_stable_when_invocation_month_advances(): void
    {
        $recommendationId = $this->recommendation();
        $this->event($recommendationId, '2024-10-15 00:00:00');
        $disk = Storage::disk('local');
        $path = 'dss-event-archives/dss-events-2024-10.json';

        $this->artisan('dss:archive-events')->assertSuccessful();
        $archive = $disk->get($path);
        CarbonImmutable::setTestNow('2026-11-03 12:00:00 UTC');

        $this->artisan('dss:archive-events')->assertSuccessful();

        $this->assertSame($archive, $disk->get($path));
        $this->assertSame(1, DB::table('dss_recommendation_events')->count());
    }

    public function test_row_count_mismatch_fails_closed_without_deleting(): void
    {
        $recommendationId = $this->recommendation();
        $this->event($recommendationId, '2024-10-15 00:00:00');
        $this->artisan('dss:archive-events')->assertSuccessful();
        $this->event($recommendationId, '2024-10-16 00:00:00');
        config(['dss.event_retention_approval_reference' => 'RETENTION-APPROVAL-123']);

        $this->artisan('dss:archive-events --delete-archived')->assertFailed();

        $this->assertSame(2, DB::table('dss_recommendation_events')->count());
    }

    public function test_deletion_and_non_default_retention_require_approval_reference(): void
    {
        $recommendationId = $this->recommendation();
        $this->event($recommendationId, '2024-10-15 00:00:00');

        $this->artisan('dss:archive-events --delete-archived')->assertFailed();
        $this->artisan('dss:archive-events --months=6')->assertFailed();

        $this->assertSame(1, DB::table('dss_recommendation_events')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function recommendation(): int
    {
        return DB::table('dss_recommendations')->insertGetId([
            'detector_id' => 'test.archive',
            'domain' => 'test',
            'type' => 'archive_test',
            'rationale' => json_encode(['rule' => 'test'], JSON_THROW_ON_ERROR),
            'dedupe_key' => uniqid('archive-', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function event(int $recommendationId, string $createdAt, ?string $reason = null): void
    {
        DB::table('dss_recommendation_events')->insert([
            'recommendation_id' => $recommendationId,
            'event' => 'viewed',
            'reason' => $reason,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
