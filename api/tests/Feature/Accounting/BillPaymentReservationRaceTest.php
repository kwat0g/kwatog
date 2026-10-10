<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\BillPaymentStatus;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillPayment;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class BillPaymentReservationRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed([ChartOfAccountsSeeder::class, WorkflowSeeder::class]);
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_simultaneous_requests_cannot_reserve_more_than_bill_balance(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for a real concurrent payment race.');
        }

        $creator = $this->user('system_admin');
        $makers = [$this->user('finance_officer'), $this->user('finance_officer')];
        $vendor = Vendor::create(['name' => 'Concurrent payment vendor']);
        $expenseAccount = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashAccount = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $bill = app(BillService::class)->create([
            'provenance_type' => 'service',
            'exception_evidence' => 'Approved service completion report for the concurrency test.',
            'exception_approved' => true,
            'bill_number' => 'RACE-'.strtoupper(bin2hex(random_bytes(5))),
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-10-04',
            'is_vatable' => false,
            'items' => [[
                'expense_account_id' => $expenseAccount,
                'description' => 'Single final balance',
                'quantity' => '1',
                'unit_price' => '100000.00',
            ]],
        ], $creator);

        $this->assertSame('100000.00', (string) $bill->balance);
        $this->assertSame(BillStatus::Unpaid, $bill->status);
        $this->assertSame('posted', $bill->journalEntry->status->value);
        $this->commitFixtureTransaction();

        $race = $this->racePaymentRequests($bill->id, [
            [
                'actor_id' => $makers[0]->id,
                'application_name' => 'billpay_race_a',
                'payload' => [
                    'cash_account_id' => $cashAccount,
                    'payment_date' => '2026-10-04',
                    'amount' => '75000.00',
                    'payment_method' => PaymentMethod::BankTransfer->value,
                    'idempotency_key' => 'billpay-race-a-'.bin2hex(random_bytes(4)),
                ],
            ],
            [
                'actor_id' => $makers[1]->id,
                'application_name' => 'billpay_race_b',
                'payload' => [
                    'cash_account_id' => $cashAccount,
                    'payment_date' => '2026-10-04',
                    'amount' => '75000.00',
                    'payment_method' => PaymentMethod::BankTransfer->value,
                    'idempotency_key' => 'billpay-race-b-'.bin2hex(random_bytes(4)),
                ],
            ],
        ]);

        $this->assertSame(2, $race['blocked_workers'], 'Both payment requests must contend on the same locked bill row.');
        $this->assertCount(1, array_filter($race['attempts'], static fn (array $attempt): bool => $attempt['outcome'] === 'created'));
        $rejections = array_values(array_filter($race['attempts'], static fn (array $attempt): bool => $attempt['outcome'] === 'rejected'));
        $this->assertCount(1, $rejections);
        $this->assertSame('Payment 75000.00 exceeds the unreserved balance 25000.00 (pending payment requests hold the rest).', $rejections[0]['message']);

        $payment = BillPayment::query()->where('bill_id', $bill->id)->sole();
        $this->assertSame(BillPaymentStatus::PendingApproval, $payment->status);
        $this->assertContains($payment->created_by, array_map(static fn (User $maker): int => $maker->id, $makers));
        $this->assertSame('75000.00', (string) $payment->amount);
        $this->assertSame(BillStatus::Unpaid, $bill->fresh()->status);
        $this->assertSame('100000.00', (string) $bill->fresh()->balance);
        $this->assertSame('0.00', (string) $bill->fresh()->amount_paid);
        $this->assertSame(1, DB::table('approval_records')
            ->where('approvable_type', BillPayment::class)
            ->where('approvable_id', $payment->id)
            ->count());
        $this->assertSame(0, JournalEntry::query()
            ->where('reference_type', 'bill_payment')
            ->where('reference_id', $bill->id)
            ->count());
        $this->assertSame(1, BillPayment::query()->where('bill_id', $bill->id)->count());
    }

    /**
     * @param  array<int, array{actor_id: int, application_name: string, payload: array<string, string>}>  $workers
     * @return array{blocked_workers: int, attempts: array<int, array<string, mixed>>}
     */
    private function racePaymentRequests(int $billId, array $workers): array
    {
        $running = [];
        DB::beginTransaction();
        DB::select('SELECT id FROM bills WHERE id = ? FOR UPDATE', [$billId]);

        foreach ($workers as $worker) {
            $resultPath = sys_get_temp_dir().'/bill-payment-race-'.getmypid().'-'.bin2hex(random_bytes(6)).'.json';
            [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Could not start a payment race worker.');
            }

            if ($pid === 0) {
                fclose($parentSocket);
                try {
                    $base = config('database.connections.'.config('database.default'));
                    config(['database.connections.bill_payment_race' => $base, 'database.default' => 'bill_payment_race']);
                    DB::purge('bill_payment_race');
                    DB::connection('bill_payment_race')->getPdo();
                    DB::selectOne('SELECT set_config(?, ?, false)', ['application_name', $worker['application_name']]);
                    fwrite($childSocket, "ready\n");
                    if (trim((string) fgets($childSocket)) !== 'go') {
                        throw new \RuntimeException('Payment race worker did not receive its start signal.');
                    }

                    $payment = app(BillService::class)->recordPayment(
                        Bill::query()->findOrFail($billId),
                        $worker['payload'],
                        User::query()->findOrFail($worker['actor_id']),
                    );
                    $attempt = ['outcome' => 'created', 'payment_id' => $payment->id];
                } catch (Throwable $exception) {
                    $attempt = [
                        'outcome' => $exception instanceof BusinessRuleException ? 'rejected' : 'error',
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ];
                }

                file_put_contents($resultPath, json_encode($attempt, JSON_THROW_ON_ERROR));
                fclose($childSocket);
                exit(0);
            }

            fclose($childSocket);
            stream_set_timeout($parentSocket, 10);
            $running[] = ['pid' => $pid, 'socket' => $parentSocket, 'path' => $resultPath];
        }

        foreach ($running as $worker) {
            if (trim((string) fgets($worker['socket'])) !== 'ready') {
                DB::commit();
                throw new \RuntimeException('A payment race worker failed to initialize.');
            }
            fwrite($worker['socket'], "go\n");
            fflush($worker['socket']);
        }

        $deadline = microtime(true) + 10;
        $blockedWorkers = 0;
        do {
            $blockedWorkers = (int) DB::table('pg_stat_activity')
                ->whereIn('application_name', array_column($workers, 'application_name'))
                ->where('wait_event_type', 'Lock')
                ->count();
            if ($blockedWorkers === count($workers)) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        DB::commit();
        $attempts = [];
        foreach ($running as $worker) {
            pcntl_waitpid($worker['pid'], $status);
            fclose($worker['socket']);
            $raw = file_get_contents($worker['path']);
            @unlink($worker['path']);
            if ($raw === false) {
                $attempts[] = ['outcome' => 'error', 'message' => 'Payment race worker wrote no result.'];

                continue;
            }
            $attempts[] = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        }

        return ['blocked_workers' => $blockedWorkers, 'attempts' => $attempts];
    }

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'is_active' => true,
        ]);
    }

    private function commitFixtureTransaction(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
    }
}
