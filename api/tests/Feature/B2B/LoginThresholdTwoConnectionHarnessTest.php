<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Services\B2bAuthService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Real PostgreSQL interleaving proof for both portal lockout audiences. */
class LoginThresholdTwoConnectionHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_failure_waits_for_authoritative_user_lock(): void
    {
        $this->assertFailureCannotLoseThresholdIncrement('supplier');
    }

    public function test_customer_failure_waits_for_authoritative_user_lock(): void
    {
        $this->assertFailureCannotLoseThresholdIncrement('customer');
    }

    public function test_supplier_failure_observes_successful_counter_reset(): void
    {
        $this->assertFailureObservesCounterReset('supplier');
    }

    public function test_customer_failure_observes_successful_counter_reset(): void
    {
        $this->assertFailureObservesCounterReset('customer');
    }

    private function assertFailureCannotLoseThresholdIncrement(string $audience): void
    {
        $this->requirePostgresForks();
        $this->seed(SettingsSeeder::class);

        $user = $this->makeUser($audience, ['failed_login_attempts' => 4]);
        $modelClass = $user::class;
        $resultFile = tempnam(sys_get_temp_dir(), 'b2b-login-race-');
        $this->assertIsString($resultFile);

        $this->commitFixtureTransaction();
        DB::beginTransaction();
        $modelClass::query()->whereKey($user->getKey())->lockForUpdate()->first();

        $pid = $this->forkFailedLogin($modelClass, $user->email, $audience, $resultFile);
        usleep(250000);
        $this->assertSame('', (string) @file_get_contents($resultFile), 'Child must wait on the portal user row lock.');

        DB::commit();
        pcntl_waitpid($pid, $status);

        $fresh = $modelClass::query()->findOrFail($user->getKey());
        $this->assertStringStartsWith('failed:', (string) file_get_contents($resultFile));
        $this->assertSame(5, (int) $fresh->failed_login_attempts);
        $this->assertNotNull($fresh->locked_until);
        @unlink($resultFile);
    }

    private function assertFailureObservesCounterReset(string $audience): void
    {
        $this->requirePostgresForks();
        $this->seed(SettingsSeeder::class);

        $user = $this->makeUser($audience, ['failed_login_attempts' => 2]);
        $modelClass = $user::class;
        $resultFile = tempnam(sys_get_temp_dir(), 'b2b-login-reset-race-');
        $this->assertIsString($resultFile);

        $this->commitFixtureTransaction();
        DB::beginTransaction();
        $modelClass::query()->whereKey($user->getKey())->lockForUpdate()->first();

        $pid = $this->forkFailedLogin($modelClass, $user->email, $audience, $resultFile);
        usleep(250000);
        $this->assertSame('', (string) @file_get_contents($resultFile), 'Child must wait before reading the reset counter.');

        DB::table($user->getTable())->whereKey($user->getKey())->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ]);
        DB::commit();
        pcntl_waitpid($pid, $status);

        $fresh = $modelClass::query()->findOrFail($user->getKey());
        $this->assertStringStartsWith('failed:', (string) file_get_contents($resultFile));
        $this->assertSame(1, (int) $fresh->failed_login_attempts);
        $this->assertNull($fresh->locked_until);
        @unlink($resultFile);
    }

    private function forkFailedLogin(string $modelClass, string $email, string $audience, string $resultFile): int
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            $base = config('database.connections.'.config('database.default'));
            config(['database.connections.b2b_harness' => $base, 'database.default' => 'b2b_harness']);
            DB::purge('b2b_harness');
            DB::connection('b2b_harness')->getPdo();

            try {
                app(B2bAuthService::class)->login(
                    $modelClass,
                    $email,
                    'definitely-wrong-password',
                    Request::create('/api/v1/b2b/'.$audience.'/login', 'POST'),
                    $audience.'-portal',
                    $audience,
                    $audience === 'customer' ? 'customer_portal' : null,
                );
                file_put_contents($resultFile, 'unexpected-success');
            } catch (\Throwable $exception) {
                file_put_contents($resultFile, 'failed:'.$exception::class);
            }

            exit(0);
        }

        return $pid;
    }

    /** @param array<string, mixed> $overrides */
    private function makeUser(string $audience, array $overrides = []): CustomerPortalUser|SupplierPortalUser
    {
        if ($audience === 'customer') {
            $customer = Customer::factory()->create();

            return CustomerPortalUser::create(array_merge([
                'customer_id' => $customer->id,
                'name' => 'Customer Lockout Harness',
                'email' => 'customer-lockout-'.uniqid().'@t.test',
                'password' => Hash::make('Correct-1!'),
                'is_active' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ], $overrides));
        }

        $vendor = Vendor::factory()->create();

        return SupplierPortalUser::create(array_merge([
            'vendor_id' => $vendor->id,
            'name' => 'Supplier Lockout Harness',
            'email' => 'supplier-lockout-'.uniqid().'@t.test',
            'password' => Hash::make('Correct-1!'),
            'is_active' => true,
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ], $overrides));
    }

    private function requirePostgresForks(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
    }

    private function commitFixtureTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
    }
}
