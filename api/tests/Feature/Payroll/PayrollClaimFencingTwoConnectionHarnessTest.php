<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Paused-worker/takeover proof using two independent PostgreSQL connections. */
class PayrollClaimFencingTwoConnectionHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_worker_is_fenced_after_a_blocked_takeover_commits(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $role = (int) DB::table('roles')->insertGetId([
            'name' => 'payroll-harness', 'slug' => 'payroll-harness', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor = (int) DB::table('users')->insertGetId([
            'name' => 'Payroll Harness', 'email' => 'payroll-harness-'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $role, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldToken = 'stale-worker-token-'.bin2hex(random_bytes(8));
        $periodId = (int) DB::table('payroll_periods')->insertGetId([
            'period_start' => '2020-01-01', 'period_end' => '2020-01-15', 'payroll_date' => '2020-01-15',
            'is_first_half' => true, 'is_thirteenth_month' => false, 'status' => 'processing',
            'created_by' => $actor, 'processing_started_at' => now()->subHours(2),
            'processing_token' => $oldToken, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = tempnam(sys_get_temp_dir(), 'payroll-fence-');

        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
        DB::beginTransaction();
        DB::table('payroll_periods')->where('id', $periodId)->lockForUpdate()->first();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $base = config('database.connections.'.config('database.default'));
            config(['database.connections.harness' => $base, 'database.default' => 'harness']);
            DB::connection('harness')->getPdo();
            try {
                $claimed = app(PayrollPeriodService::class)->claimForCompute(PayrollPeriod::findOrFail($periodId));
                file_put_contents($result, (string) $claimed->processing_token);
            } catch (\Throwable $e) {
                file_put_contents($result, 'error:'.$e->getMessage());
            }
            exit(0);
        }
        usleep(250000);
        $this->assertSame('', (string) @file_get_contents($result), 'Takeover must wait on the period row lock.');
        DB::commit();
        pcntl_waitpid($pid, $status);
        $newToken = (string) file_get_contents($result);
        $this->assertNotSame('', $newToken);
        $this->assertNotSame($oldToken, $newToken);

        $stale = (new PayrollPeriod)->newQuery()->findOrFail($periodId);
        try {
            app(PayrollPeriodService::class)->assertComputeClaim($stale, $oldToken);
            $this->fail('The paused worker must be fenced after takeover.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Payroll compute claim is no longer owned by this worker.', $e->getMessage());
        }
        $this->assertSame($newToken, (string) DB::table('payroll_periods')->where('id', $periodId)->value('processing_token'));
        @unlink($result);
    }
}
