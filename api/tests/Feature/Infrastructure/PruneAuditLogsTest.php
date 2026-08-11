<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_is_valid_and_corrupt_final_file_is_rebuilt(): void
    {
        Storage::fake('local');

        $createdAt = Carbon::now()->subMonths(2)->startOfMonth()->addDay();
        DB::table('audit_logs')->insert([
            'action' => 'archive.test',
            'model_type' => 'test',
            'model_id' => 1,
            'old_values' => json_encode(['before' => true], JSON_THROW_ON_ERROR),
            'new_values' => json_encode(['after' => true], JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
        ]);

        $path = "audit-archives/audit-{$createdAt->format('Y-m')}.json.gz";

        $this->artisan('audit:prune', ['--months' => 1])
            ->assertSuccessful();

        Storage::disk('local')->assertExists($path);
        $payload = json_decode(
            gzdecode(Storage::disk('local')->get($path)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame(1, $payload['row_count']);
        $this->assertCount(1, $payload['rows']);

        // Simulate a previously published but interrupted/corrupt archive.
        Storage::disk('local')->put($path, 'not-a-gzip');

        $this->artisan('audit:prune', ['--months' => 1])
            ->assertSuccessful();

        $rebuilt = gzdecode(Storage::disk('local')->get($path));
        $this->assertIsString($rebuilt);
        $this->assertSame(1, json_decode($rebuilt, true, 512, JSON_THROW_ON_ERROR)['row_count']);
    }
}
