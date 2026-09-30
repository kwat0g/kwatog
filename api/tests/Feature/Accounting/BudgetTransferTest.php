<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\BudgetTransferService;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_request_then_approve_moves_the_month_bucket(): void
    {
        [$service, $maker, $checker, $from, $to] = $this->livePair('1000.00', '1000.00');

        $transfer = $service->request([
            'from_line_item_id' => $from->id,
            'to_line_item_id' => $to->id,
            'month' => 'jan',
            'amount' => '300.00',
            'reason' => 'Shift Q1 funds to tools.',
        ], $maker->id);
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertNotEmpty($transfer->transfer_number);

        // Nothing moves while pending.
        $this->assertSame('1000.00', $from->fresh()->jan);

        $approved = $service->approve($transfer, $checker->id);
        $this->assertSame('approved', $approved->status);
        $this->assertSame('700.00', $from->fresh()->jan);
        $this->assertSame('1300.00', $to->fresh()->jan);
        // annual_total is a stored generated column: it follows the buckets.
        $this->assertSame('700.00', $from->fresh()->annual_total);
        $this->assertSame('1300.00', $to->fresh()->annual_total);
    }

    public function test_transfer_maker_checker_is_enforced_by_user_and_role(): void
    {
        [$service, $maker, , $from, $to] = $this->livePair('1000.00', '1000.00');
        $sameRole = User::factory()->withRole('finance_officer')->create();
        $checker = User::factory()->withRole('vice_president')->create();

        $transfer = $service->request([
            'from_line_item_id' => $from->id,
            'to_line_item_id' => $to->id,
            'month' => 'jan',
            'amount' => '100.00',
            'reason' => 'SoD probe transfer.',
        ], $maker->id);

        try {
            $service->approve($transfer, $maker->id);
            $this->fail('The requesting user must not approve their own transfer.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        try {
            $service->approve($transfer->fresh(), $sameRole->id);
            $this->fail('A second holder of the requesting role must not approve the transfer.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('different role', $exception->getMessage());
        }

        $this->assertSame('approved', $service->approve($transfer->fresh(), $checker->id)->status);
    }

    public function test_transfer_guards_reject_bad_pairs_and_over_headroom_amounts(): void
    {
        [$service, $maker, $checker, $from, $to] = $this->livePair('100.00', '0.00');
        $payload = fn (array $over): array => array_merge([
            'from_line_item_id' => $from->id,
            'to_line_item_id' => $to->id,
            'month' => 'jan',
            'amount' => '10.00',
            'reason' => 'Guard probe transfer.',
        ], $over);

        foreach ([
            'same line' => ['from_line_item_id' => $from->id, 'to_line_item_id' => $from->id],
            'bad month' => ['month' => 'foo'],
            'blank reason' => ['reason' => '  '],
            'over month bucket' => ['amount' => '150.00'],
        ] as $case => $over) {
            try {
                $service->request($payload($over), $maker->id);
                $this->fail("Transfer guard missed case: {$case}.");
            } catch (BusinessRuleException) {
                $this->addToAssertionCount(1);
            }
        }

        // Annual headroom is tighter than the month bucket here.
        $from->forceFill(['actual_total' => '80.00'])->save();
        try {
            $service->request($payload(['amount' => '30.00']), $maker->id);
            $this->fail('Transfer over annual headroom must be refused.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('headroom', $exception->getMessage());
        }
        $from->forceFill(['actual_total' => '0.00'])->save();

        // Draft-budget lines never move.
        $draft = $this->draftPair();
        try {
            $service->request($payload([
                'from_line_item_id' => $draft[0]->id,
                'to_line_item_id' => $draft[1]->id,
            ]), $maker->id);
            $this->fail('Transfer from a draft budget must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        // Re-approving or approving a rejected transfer is refused.
        $transfer = $service->request($payload([]), $maker->id);
        $service->approve($transfer, $checker->id);
        try {
            $service->approve($transfer->fresh(), $checker->id);
            $this->fail('Double approval must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        $rejected = $service->request($payload([]), $maker->id);
        $service->reject($rejected, $checker->id);
        // Rejection moves nothing; the earlier approved 10.00 stays moved.
        $this->assertSame('90.00', $from->fresh()->jan);
        // The rejection is attributed to the rejecter, never the approver.
        $this->assertSame($checker->id, (int) $rejected->fresh()->rejected_by);
        $this->assertNull($rejected->fresh()->approved_by);
        try {
            $service->approve($rejected->fresh(), $checker->id);
            $this->fail('Approving a rejected transfer must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_transfer_stays_inside_one_fiscal_year_and_type(): void
    {
        [$service, $maker, , $from, $to] = $this->livePair('1000.00', '1000.00');
        $payload = [
            'from_line_item_id' => $from->id,
            'to_line_item_id' => $to->id,
            'month' => 'jan',
            'amount' => '10.00',
            'reason' => 'Scope probe transfer.',
        ];

        $otherYear = FiscalYear::factory()->create([
            'year' => 2033,
            'status' => 'active',
            'start_date' => '2033-01-01',
            'end_date' => '2033-12-31',
        ]);
        $foreign = $this->lineInYear($otherYear->id, 'operating');
        try {
            $service->request([...$payload, 'to_line_item_id' => $foreign->id], $maker->id);
            $this->fail('Cross-year transfer must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        $capital = $this->lineInYear($from->budget->fiscal_year_id, 'capital');
        try {
            $service->request([...$payload, 'to_line_item_id' => $capital->id], $maker->id);
            $this->fail('Cross-type transfer must be refused.');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }

        // A live budget in a closed year cannot move money either.
        FiscalYear::query()->whereKey($from->budget->fiscal_year_id)->update(['status' => 'closed']);
        try {
            $service->request($payload, $maker->id);
            $this->fail('Transfer in a closed fiscal year must be refused.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('closed', $exception->getMessage());
        }
    }

    public function test_transfer_http_endpoints_bind_hash_ids_and_enforce_roles(): void
    {
        [$service, $maker, $checker, $from, $to] = $this->livePair('1000.00', '1000.00');

        // Maker requests over HTTP with line hash ids.
        $create = $this->actingAs($maker)->postJson('/api/v1/budget-transfers', [
            'from_line_item_id' => $from->hash_id,
            'to_line_item_id' => $to->hash_id,
            'month' => 'jan',
            'amount' => '25.00',
            'reason' => 'HTTP binding probe transfer.',
        ])->assertCreated();
        $hash = $create->json('data.id');
        $this->assertNotEmpty($hash);

        // Show resolves the same hash (route-model binding).
        $this->actingAs($maker)->getJson("/api/v1/budget-transfers/{$hash}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        // Same-role checker is refused over HTTP with the sentence.
        $sameRole = User::factory()->withRole('finance_officer')->create();
        $this->actingAs($sameRole)->postJson("/api/v1/budget-transfers/{$hash}/approve")
            ->assertStatus(422)
            ->assertJsonPath('errors.error.0', 'Transfer approval requires a different role than the requesting role.');

        // Cross-role checker applies.
        $this->actingAs($checker)->postJson("/api/v1/budget-transfers/{$hash}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertSame('975.00', $from->fresh()->jan);

        // A role without the grant cannot even list.
        $outsider = User::factory()->withRole('employee')->create();
        $this->actingAs($outsider)->getJson('/api/v1/budget-transfers')->assertForbidden();
    }

    /**
     * @return array{0:BudgetTransferService,1:User,2:User,3:BudgetLineItem,4:BudgetLineItem}
     */
    private function livePair(string $fromJan, string $toJan): array
    {
        $fiscalYear = FiscalYear::factory()->create([
            'year' => 2026,
            'status' => 'active',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $department = Department::factory()->create();
        $maker = User::factory()->withRole('finance_officer')->create();
        $checker = User::factory()->withRole('vice_president')->create();

        $budgets = app(BudgetService::class);
        $makeLine = function (string $name, string $jan) use ($budgets, $fiscalYear, $department, $maker, $checker): BudgetLineItem {
            $account = Account::create([
                'code' => 'BT-'.substr(uniqid(), -6),
                'name' => $name,
                'type' => 'expense',
                'normal_balance' => 'debit',
                'is_active' => true,
            ]);
            $budget = $budgets->create([
                'fiscal_year_id' => $fiscalYear->id,
                'department_id' => $department->id,
                'budget_type' => 'operating',
                'name' => $name.' '.substr(uniqid(), -4),
            ], [['account_id' => $account->id, 'jan' => $jan]]);
            $budgets->approve($budgets->submit($budget, $maker->id)->fresh(), $checker->id);

            return $budget->lineItems()->firstOrFail()->fresh();
        };

        // The closure binds $maker/$checker from the outer scope, so the
        // budgets it activates are finance-submitted and VP-approved.
        return [app(BudgetTransferService::class), $maker, $checker, $makeLine('Source', $fromJan), $makeLine('Target', $toJan)];
    }

    /** Two lines on one draft budget (same FY/type, never activated). */
    private function draftPair(): array
    {
        $fiscalYear = FiscalYear::query()->where('year', 2026)->firstOrFail();
        $department = Department::factory()->create();
        $lines = [];
        foreach (['Draft A', 'Draft B'] as $name) {
            $account = Account::create([
                'code' => 'BD-'.substr(uniqid(), -6),
                'name' => $name,
                'type' => 'expense',
                'normal_balance' => 'debit',
                'is_active' => true,
            ]);
            $budget = app(BudgetService::class)->create([
                'fiscal_year_id' => $fiscalYear->id,
                'department_id' => $department->id,
                'budget_type' => 'operating',
                'name' => $name.' '.substr(uniqid(), -4),
            ], [['account_id' => $account->id, 'jan' => '500.00']]);
            $lines[] = $budget->lineItems()->firstOrFail()->fresh();
        }

        return $lines;
    }

    private function lineInYear(int $fiscalYearId, string $budgetType): BudgetLineItem
    {
        $account = Account::create([
            'code' => 'BY-'.substr(uniqid(), -6),
            'name' => 'Scope probe',
            'type' => $budgetType === 'capital' ? 'asset' : 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        $maker = User::factory()->withRole('finance_officer')->create();
        $checker = User::factory()->withRole('vice_president')->create();
        $budgets = app(BudgetService::class);
        $budget = $budgets->create([
            'fiscal_year_id' => $fiscalYearId,
            'department_id' => Department::factory()->create()->id,
            'budget_type' => $budgetType,
            'name' => 'Scope probe '.substr(uniqid(), -4),
        ], [['account_id' => $account->id, 'jan' => '500.00']]);
        $budgets->approve($budgets->submit($budget, $maker->id)->fresh(), $checker->id);

        return $budget->lineItems()->firstOrFail()->fresh();
    }
}
