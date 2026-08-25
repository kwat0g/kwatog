<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\Export\ScheduledExportArtifactService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScheduledExportArtifactServiceTest extends TestCase
{
    public function test_artifact_is_stored_on_the_private_disk(): void
    {
        Storage::fake('local');

        $path = app(ScheduledExportArtifactService::class)->store('csv-bytes', 'employees.csv');

        Storage::disk('local')->assertExists($path);
        $this->assertStringContainsString('exports/scheduled/', $path);
    }

    public function test_artifact_size_limit_is_enforced_before_queueing(): void
    {
        $this->expectException(\RuntimeException::class);

        app(ScheduledExportArtifactService::class)->store(
            str_repeat('x', ScheduledExportArtifactService::MAX_BYTES + 1),
            'too-large.csv',
        );
    }
}
