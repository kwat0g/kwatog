<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Listeners\GenerateBankFileOnPayrollFinalized;
use App\Modules\Payroll\Models\BankFileRecord;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\BankFileService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BankFileAutoGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);

        // Isolate from the unrelated NotifyEmployeesOnPayrollFinalized listener.
        // That listener queries employees.user_id (a known pre-existing schema
        // mismatch — the FK actually lives on users.employee_id). Its catch
        // swallows the PHP exception, but on PostgreSQL the bad SQL aborts the
        // outer RefreshDatabase transaction, which then poisons every query
        // our listener tries to run. Re-register only our listener for the
        // duration of this test class.
        Event::forget(PayrollPeriodFinalized::class);
        Event::listen(
            PayrollPeriodFinalized::class,
            [GenerateBankFileOnPayrollFinalized::class, 'handle'],
        );
    }

    private function makeSystemAdmin(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        return User::create([
            'name'      => 'SysAdmin '.uniqid(),
            'email'     => 'sa_'.uniqid().'@x.test',
            'password'  => bcrypt('Password1!'),
            'role_id'   => $roleId,
            'is_active' => true,
        ]);
    }

    /** Approved period with one banked employee + one valid payroll. */
    private function makeApprovedPeriodWithBankedPayroll(): PayrollPeriod
    {
        $period = PayrollPeriod::factory()->create([
            'period_start'  => '2026-04-01',
            'period_end'    => '2026-04-15',
            'payroll_date'  => '2026-04-15',
            'is_first_half' => true,
            'status'        => PayrollPeriodStatus::Approved->value,
        ]);

        $employee = Employee::factory()->create([
            'bank_name'        => 'BPI',
            'bank_account_no'  => '1234567890',
        ]);

        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id'       => $employee->id,
            'gross_pay'         => 20000,
            'net_pay'           => 18000,
            'error_message'     => null,
            'computed_at'       => now(),
        ]);

        return $period;
    }

    public function test_finalize_generates_bank_file_record_when_system_admin_exists(): void
    {
        Storage::fake('local');
        $this->makeSystemAdmin();
        $period = $this->makeApprovedPeriodWithBankedPayroll();

        /** @var PayrollPeriodService $svc */
        $svc = app(PayrollPeriodService::class);
        $svc->finalize($period);

        $record = BankFileRecord::where('payroll_period_id', $period->id)->first();

        $this->assertNotNull($record, 'BankFileRecord should be created on finalize');
        $this->assertGreaterThanOrEqual(1, $record->record_count);
        $this->assertGreaterThan(0, (float) $record->total_amount);
        $this->assertStringStartsWith('bank-files', $record->file_path);
        Storage::disk('local')->assertExists($record->file_path);
    }

    public function test_listener_skips_when_no_system_admin_exists(): void
    {
        Storage::fake('local');

        // Remove any system_admin users — none should exist after seeding the
        // role but before we attach a user to it.
        User::whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))->delete();

        $period = $this->makeApprovedPeriodWithBankedPayroll();

        $logSpy = Log::spy();

        /** @var PayrollPeriodService $svc */
        $svc = app(PayrollPeriodService::class);
        $svc->finalize($period);

        $this->assertNull(
            BankFileRecord::where('payroll_period_id', $period->id)->first(),
            'No bank file should be generated when no system_admin exists',
        );

        $logSpy->shouldHaveReceived('channel')->with('stack');
    }

    public function test_listener_swallows_bank_file_failure(): void
    {
        Storage::fake('local');
        $this->makeSystemAdmin();
        $period = $this->makeApprovedPeriodWithBankedPayroll();

        // Force the BankFileService to blow up so the listener's catch() runs.
        $mock = Mockery::mock(BankFileService::class);
        $mock->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('boom'));
        $this->app->instance(BankFileService::class, $mock);

        $logSpy = Log::spy();

        /** @var PayrollPeriodService $svc */
        $svc = app(PayrollPeriodService::class);

        // Must not bubble — listener owns the failure.
        $finalized = $svc->finalize($period);

        $this->assertSame(PayrollPeriodStatus::Finalized, $finalized->status);
        $this->assertNull(BankFileRecord::where('payroll_period_id', $period->id)->first());
        $logSpy->shouldHaveReceived('error')->once();
    }
}
