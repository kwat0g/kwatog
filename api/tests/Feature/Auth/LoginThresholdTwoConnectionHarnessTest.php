<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Real PostgreSQL interleaving proof for the failed-login threshold. */
class LoginThresholdTwoConnectionHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_failure_waiting_on_user_lock_cannot_lose_threshold_increment(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $role = (int) DB::table('roles')->insertGetId([
            'name' => 'login-harness', 'slug' => 'login-harness', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $email = 'login-harness-'.uniqid().'@x.test';
        $id = (int) DB::table('users')->insertGetId([
            'name' => 'Login Harness', 'email' => $email, 'password' => Hash::make('correct-password'),
            'role_id' => $role, 'is_active' => true, 'failed_login_attempts' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = tempnam(sys_get_temp_dir(), 'login-race-');

        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
        DB::beginTransaction();
        DB::table('users')->where('id', $id)->lockForUpdate()->first();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $base = config('database.connections.'.config('database.default'));
            config(['database.connections.harness' => $base, 'database.default' => 'harness']);
            DB::connection('harness')->getPdo();
            try {
                app(AuthService::class)->login($email, 'wrong-password', Request::create('/login'));
                file_put_contents($result, 'unexpected-success');
            } catch (\Throwable $e) {
                file_put_contents($result, 'failed:'.$e::class);
            }
            exit(0);
        }
        usleep(250000);
        $this->assertSame('', (string) @file_get_contents($result), 'Child failure must wait on the authoritative user lock.');
        DB::commit();
        pcntl_waitpid($pid, $status);

        $row = DB::table('users')->where('id', $id)->first();
        $this->assertStringStartsWith('failed:', (string) file_get_contents($result));
        $this->assertSame(5, (int) $row->failed_login_attempts);
        $this->assertNotNull($row->locked_until);
        @unlink($result);
    }
}
