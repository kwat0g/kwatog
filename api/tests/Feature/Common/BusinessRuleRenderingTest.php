<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Accounting\Exceptions\LedgerImbalanceException;
use App\Modules\Accounting\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\MRP\Exceptions\BomStructureException;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * A violated business rule must render as 422 with its message.
 *
 * Service layers across this codebase signalled rules with a bare
 * RuntimeException. bootstrap/app.php deliberately does not map that class
 * (QueryException extends it, and a broad rule would disguise SQL faults as
 * validation errors), so every such rule reached the browser as a 500 from any
 * endpoint whose controller did not happen to wrap the call in
 * `catch (\RuntimeException)`. With APP_DEBUG=false the operator saw "Server
 * Error" for a condition they could have fixed themselves.
 *
 * These tests pin the two halves of the contract:
 *   1. the render mapping — BusinessRuleException and its subclasses are 422
 *      with the message, a bare RuntimeException is still a 500;
 *   2. one real endpoint that changed, end to end.
 */
class BusinessRuleRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // 1. The render mapping, through the real HTTP kernel
    // ─────────────────────────────────────────────────────────────

    public function test_business_rule_exception_renders_as_422_carrying_its_message(): void
    {
        Route::middleware('api')->get('/api/v1/_test/business-rule', function (): never {
            throw new BusinessRuleException('A bill must have at least one line item.');
        });

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/_test/business-rule')
            ->assertStatus(422)
            ->assertJson([
                'message' => 'A bill must have at least one line item.',
                'errors'  => ['error' => ['A bill must have at least one line item.']],
            ])
            // A rule with no client-side branch declares no code, and the
            // envelope stays byte-identical for every existing caller.
            ->assertJsonMissingPath('code');
    }

    public function test_named_subclass_adds_its_code_and_errors_bag_key(): void
    {
        Route::middleware('api')->get('/api/v1/_test/bom-structure', function (): never {
            throw new BomStructureException(
                'Circular bill of materials detected while exploding product 7 (item RM-001).'
            );
        });

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/_test/bom-structure')
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Circular bill of materials detected while exploding product 7 (item RM-001).',
                'code'    => 'bom_structure',
            ])
            // Shares MissingBomException's errors-bag key on purpose:
            // ChainErrorPanel offers "Manage BOMs" on `errors.bom`, and a
            // circular BOM is fixed in the same editor as an absent one.
            ->assertJsonStructure(['errors' => ['bom']]);
    }

    public function test_bare_runtime_exception_is_still_a_500(): void
    {
        // The control for the two assertions above. QueryException extends
        // RuntimeException, so widening the render rule to that class would
        // report every SQL fault as a validation error. A misconfiguration
        // ("Required account 1010 not found in COA") must keep failing loudly.
        Route::middleware('api')->get('/api/v1/_test/bare-runtime', function (): never {
            throw new RuntimeException('Required account 1010 not found in COA.');
        });

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/_test/bare-runtime')
            ->assertStatus(500);
    }

    // ─────────────────────────────────────────────────────────────
    // 2. A real endpoint that returned 500 before the conversion
    // ─────────────────────────────────────────────────────────────

    public function test_finalizing_a_clearance_without_a_computed_amount_is_422_not_500(): void
    {
        // SeparationController::finalize has no try/catch, so
        // FinalPayService::postJournalEntry's 'Compute final pay before posting
        // JE.' reached the browser as a 500 — one line below a
        // BusinessRuleException raised for the same class of failure.
        //
        // final_pay_computed passes SeparationService::finalize's own guard;
        // the null amount is what postJournalEntry rejects.
        $employee = Employee::factory()->create();
        $clearance = Clearance::factory()->create([
            'employee_id'        => $employee->id,
            'status'             => ClearanceStatus::Completed->value,
            'final_pay_computed' => true,
            'final_pay_amount'   => null,
        ]);

        $this->actingAs($this->makeAdmin())
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/finalize")
            ->assertStatus(422)
            ->assertJson(['message' => 'Compute final pay before posting JE.']);
    }

    // ─────────────────────────────────────────────────────────────
    // 3. The three accounting classes that stay outside the family
    // ─────────────────────────────────────────────────────────────

    /**
     * ClosedPeriodException does NOT extend BusinessRuleException — two
     * controllers depend on that in writing (GoodsReceiptNoteController's
     * docblock, WorkOrderController::recordOutput), because reparenting it would
     * move it inside the family ~20 GL-handoff arms degrade to manual, and the
     * operator would stop being told which period to reopen.
     *
     * It still must not be a 500 where no controller names it, so it states its
     * own 422. Laravel calls an exception's render() only when nothing caught it,
     * so all 21 existing arms are untouched — ControllerSqlLeakTest pins one of
     * them.
     */
    public function test_a_closed_period_renders_as_422_even_where_no_controller_names_it(): void
    {
        Route::middleware('api')->get('/api/v1/_test/closed-period', function (): never {
            throw new ClosedPeriodException(2026, 7, '2026-07-15');
        });

        $response = $this->actingAs($this->makeAdmin())->getJson('/api/v1/_test/closed-period');

        $response->assertStatus(422);
        $this->assertStringContainsString('2026-07 is closed', (string) $response->json('message'));
        $this->assertStringContainsString('Reopen the period first', (string) $response->json('message'));
    }

    /**
     * The endpoint that made the arm above worth having.
     *
     * AssetService::dispose back-dates its disposal JE to `disposed_date`, so it
     * reaches AccountingPeriodService::assertPostingAllowed — and AssetController
     * has no try/catch at all. An accountant disposing an asset into last month's
     * closed period got a 500 and "Server Error" for a condition whose own
     * message names the period to reopen.
     */
    public function test_disposing_an_asset_into_a_closed_period_is_422_not_500(): void
    {
        $this->seed([ChartOfAccountsSeeder::class, SettingsSeeder::class]);
        // Production runs with APP_DEBUG=false, which is the configuration where
        // the operator saw "Server Error" and nothing else.
        config(['app.debug' => false]);

        $admin = $this->makeAdmin();
        app(AccountingPeriodService::class)->close(2026, 7, $admin);

        $asset = Asset::query()->create([
            'asset_code'               => 'AST-CP-'.substr(uniqid(), -6),
            'name'                     => 'Closed-period CNC Machine',
            'category'                 => AssetCategory::Equipment->value,
            'acquisition_date'         => '2026-01-15',
            'acquisition_cost'         => 100000,
            'useful_life_years'        => 5,
            'salvage_value'            => 0,
            'accumulated_depreciation' => 20000,
            'status'                   => AssetStatus::Active->value,
        ]);

        $response = $this->actingAs($admin)->postJson("/api/v1/assets/{$asset->hash_id}/dispose", [
            'disposal_amount' => '40000.00',
            'disposed_date'   => '2026-07-15',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('2026-07 is closed', (string) $response->json('message'));
        $this->assertSame(
            AssetStatus::Active,
            $asset->fresh()->status,
            'The refused disposal must leave the asset untouched.',
        );
    }

    /**
     * UnbalancedJournalEntryException stays a 500 where nothing names it, and
     * that is the decision rather than an oversight.
     *
     * From the JE form it is user input and JournalEntryController answers 422
     * with `errors.lines`. From an internal poster — MovementGl, PayrollGl,
     * GrnGl, AssetService, FinalPayService — the lines were built by our own code
     * from our own account mapping, so an imbalance is a bug. Telling an operator
     * to correct a form they never filled in would hide it.
     */
    public function test_an_unbalanced_journal_entry_is_still_a_500_where_nothing_names_it(): void
    {
        Route::middleware('api')->get('/api/v1/_test/unbalanced-je', function (): never {
            throw new UnbalancedJournalEntryException('100.00', '90.00');
        });

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/_test/unbalanced-je')
            ->assertStatus(500);
    }

    /**
     * LedgerImbalanceException likewise. Every JE that reaches the ledger passed
     * the balance check, so a non-zero trial balance means posted rows were
     * mutated or the aggregation is wrong. Nothing the reader of a trial balance
     * can do will fix it, and a 422 would dress a bug as a validation error —
     * exactly what bootstrap/app.php refuses to map RuntimeException to prevent.
     */
    public function test_a_ledger_imbalance_is_still_a_500(): void
    {
        Route::middleware('api')->get('/api/v1/_test/ledger-imbalance', function (): never {
            throw new LedgerImbalanceException('1000.00', '999.00');
        });

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/_test/ledger-imbalance')
            ->assertStatus(500);
    }
}
