<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneOldNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_old_read_notifications(): void
    {
        $user = User::factory()->create();

        // Old + read → should be pruned
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Old']),
            'read_at' => now()->subDays(100),
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        // Old + unread → should NOT be pruned
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Old Unread']),
            'read_at' => null,
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        // Recent + read → should NOT be pruned
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Recent']),
            'read_at' => now()->subDays(5),
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $this->artisan('notifications:prune --days=90')
            ->expectsOutputToContain('Pruned 1')
            ->assertSuccessful();

        $this->assertEquals(2, DB::table('notifications')->count());
    }
}
