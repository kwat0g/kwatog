<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\PasswordResetRequest;
use App\Modules\Auth\Services\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Real PostgreSQL connection/process proof for the single-use reset control. */
class PasswordResetTwoConnectionHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_process_waits_for_token_lock_then_replay_is_rejected(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }

        $role = (int) DB::table('roles')->insertGetId([
            'name' => 'harness-role', 'slug' => 'harness-role', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = (int) DB::table('users')->insertGetId([
            'name' => 'Reset Harness', 'email' => 'reset-harness-'.uniqid().'@x.test',
            'password' => Hash::make('old-password'), 'role_id' => $role,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $raw = 'harness-token-'.bin2hex(random_bytes(12));
        $token = PasswordResetRequest::create([
            'user_id' => $user, 'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addHour(), 'ip_address' => '127.0.0.1',
        ]);
        $result = tempnam(sys_get_temp_dir(), 'reset-race-');

        // RefreshDatabase starts an outer transaction; commit fixture setup so
        // the forked connection can see it before the interleaving begins.
        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
        DB::beginTransaction();
        DB::table('password_reset_requests')->where('id', $token->id)->lockForUpdate()->first();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            // Do not purge the inherited parent PDO after fork: closing it
            // would release the parent's row lock. Open a genuinely separate
            // PostgreSQL connection for this child instead.
            $base = config('database.connections.'.config('database.default'));
            config(['database.connections.harness' => $base, 'database.default' => 'harness']);
            DB::connection('harness')->getPdo();
            try {
                app(PasswordResetService::class)->reset($raw, 'new-password', Request::create('/reset'));
                file_put_contents($result, 'success');
            } catch (\Throwable $e) {
                file_put_contents($result, 'error:'.$e->getMessage());
            }
            exit(0);
        }

        usleep(250000);
        $this->assertSame('', (string) @file_get_contents($result), 'Child must be blocked by the token row lock.');
        DB::commit();
        pcntl_waitpid($pid, $status);
        $this->assertSame('success', file_get_contents($result));

        try {
            app(PasswordResetService::class)->reset($raw, 'replay-password', Request::create('/reset'));
            $this->fail('A consumed token must reject the replay.');
        } catch (\Throwable) {
            $this->assertDatabaseCount('password_history', 1);
            $this->assertDatabaseHas('password_reset_requests', ['id' => $token->id]);
        }
        @unlink($result);
    }
}
