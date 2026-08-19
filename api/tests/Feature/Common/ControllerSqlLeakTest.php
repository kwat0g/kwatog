<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\AccountService;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\MRP\Exceptions\MissingBomException;
use App\Modules\Production\Enums\WoOperationStatus;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WoOperationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Tests\TestCase;

/**
 * 39 controllers caught bare `\RuntimeException` around a service call and
 * answered 422 with `$e->getMessage()`.
 *
 * `Illuminate\Database\QueryException extends PDOException extends
 * RuntimeException`, so those arms also caught every SQL fault and put the
 * driver's text — SQLSTATE, table names, column names and the whole statement —
 * into a user-facing message. bootstrap/app.php refuses to map RuntimeException
 * globally for exactly this reason and says so in a comment; these 39 files
 * defeated that locally.
 *
 * These tests pin both halves of the narrowing, because getting one right at the
 * cost of the other is not a fix:
 *
 *   1. a QueryException from a service is a 500 with a generic message, and no
 *      part of the SQL reaches the client;
 *   2. every class of business rule those arms legitimately carried still
 *      reaches the client with its own sentence and its own status — including
 *      the ones that are NOT BusinessRuleException subclasses, which a
 *      literal narrowing to that single class would have silently turned into
 *      "Server Error".
 *
 * The service is swapped in the container rather than provoked through real
 * data: the subject here is what the controller does with an exception, and a
 * double is the only way to raise a deadlock or a foreign-key violation from a
 * chosen call site deterministically.
 */
class ControllerSqlLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // APP_DEBUG is true in .env.testing, and Laravel then includes the
        // exception message, file and stack trace in a 500 body. Production runs
        // with it false (CLAUDE.md), which is the configuration where "the SQL
        // must not reach the browser" is a claim about the response rather than
        // about the debug flag. Pin the production shape.
        config(['app.debug' => false]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    /**
     * A foreign-key violation, as the pgsql driver actually reports it.
     */
    private function fkViolation(): QueryException
    {
        $previous = new PDOException(
            'SQLSTATE[23503]: Foreign key violation: 7 ERROR:  insert or update on table '
            .'"accounts" violates foreign key constraint "accounts_parent_id_foreign"'
        );
        $previous->errorInfo = ['23503', 7, 'insert or update on table "accounts" violates foreign key constraint'];

        return new QueryException(
            'pgsql',
            'insert into "accounts" ("code", "name", "type", "parent_id") values (?, ?, ?, ?)',
            ['1010', 'Cash on Hand', 'asset', 999999],
            $previous,
        );
    }

    // ─────────────────────────────────────────────────────────────
    // 1. The leak itself
    // ─────────────────────────────────────────────────────────────

    public function test_a_query_exception_from_a_service_is_a_500_and_leaks_no_sql(): void
    {
        $this->mock(AccountService::class, function ($mock): void {
            $mock->shouldReceive('create')->once()->andThrow($this->fkViolation());
        });

        $response = $this->actingAs($this->admin())->postJson('/api/v1/accounts', [
            'code' => '1010',
            'name' => 'Cash on Hand',
            'type' => 'asset',
        ]);

        // Before the narrowing this was a 422 — the status itself told the
        // operator the request was theirs to fix.
        $response->assertStatus(500);

        $body = $response->getContent();
        $this->assertIsString($body);
        foreach ([
            'SQLSTATE',
            '23503',
            'accounts_parent_id_foreign',
            'insert into',
            'Connection: pgsql',
            'violates foreign key constraint',
        ] as $fragment) {
            $this->assertStringNotContainsString(
                $fragment,
                $body,
                "The 500 body still carries `{$fragment}`, so the schema is still reaching the client.",
            );
        }

        $response->assertJson(['message' => 'Server Error']);
    }

    /**
     * The 409 arms in WoOperationController were kept, so they need the same
     * proof: a shop-floor terminal must not be shown a deadlock as a conflict.
     */
    public function test_a_query_exception_on_a_409_arm_is_also_a_500(): void
    {
        $operation = $this->makeOperation();

        $deadlockPrevious = new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
        $deadlockPrevious->errorInfo = ['40P01', 7, 'deadlock detected'];
        $deadlock = new QueryException(
            'pgsql',
            'update "wo_operations" set "status" = ? where "id" = ?',
            ['in_progress', $operation->id],
            $deadlockPrevious,
        );

        $this->mock(WoOperationService::class, function ($mock) use ($deadlock): void {
            $mock->shouldReceive('completeOperation')->once()->andThrow($deadlock);
        });

        $response = $this->actingAs($this->admin())
            ->postJson("/api/v1/production/operations/{$operation->hash_id}/complete");

        $response->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
        $this->assertStringNotContainsString('wo_operations', (string) $response->getContent());
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Nothing the user needed was withheld
    // ─────────────────────────────────────────────────────────────

    public function test_a_business_rule_still_reaches_the_client_as_422_with_its_message(): void
    {
        // The control for the test above. Closing the leak by deleting the arm's
        // usefulness would pass test 1 and be a worse defect.
        $this->mock(AccountService::class, function ($mock): void {
            $mock->shouldReceive('create')->once()->andThrow(new BusinessRuleException(
                "Parent account 1000 is type 'asset', cannot host child of type 'expense'.",
            ));
        });

        $this->actingAs($this->admin())
            ->postJson('/api/v1/accounts', ['code' => '5010', 'name' => 'Salaries', 'type' => 'expense'])
            ->assertStatus(422)
            ->assertJson([
                'message' => "Parent account 1000 is type 'asset', cannot host child of type 'expense'.",
            ]);
    }

    /**
     * ClosedPeriodException extends RuntimeException directly, NOT
     * BusinessRuleException. Narrowing InvoiceController::finalize to
     * BusinessRuleException alone — the literal reading of the task — would have
     * answered a closed accounting period with a 500 and "Server Error", for a
     * condition whose own message names the period to reopen. This is why the
     * arms are unions of named classes rather than one class.
     */
    public function test_a_closed_period_still_reaches_the_client_as_422_with_its_message(): void
    {
        $invoice = Invoice::factory()->create();

        $this->mock(InvoiceService::class, function ($mock): void {
            $mock->shouldReceive('finalize')->once()->andThrow(new ClosedPeriodException(2026, 7, '2026-07-15'));
        });

        $response = $this->actingAs($this->admin())
            ->patchJson("/api/v1/invoices/{$invoice->hash_id}/finalize");

        $response->assertStatus(422);
        $this->assertStringContainsString('2026-07 is closed', (string) $response->json('message'));
        $this->assertStringContainsString('Reopen the period first', (string) $response->json('message'));
    }

    /**
     * The one arm deleted outright rather than narrowed.
     *
     * The arm re-emitted only `getMessage()`, so `errors` and `code` from
     * MissingBomException never left the server — and ChainErrorPanel decides
     * whether to offer "Manage BOMs" from exactly those two fields. Letting the
     * render hook in bootstrap/app.php answer instead is what puts the button
     * back.
     */
    public function test_confirming_a_sales_order_with_no_bom_carries_the_fields_the_spa_branches_on(): void
    {
        $so = SalesOrder::factory()->create();

        $this->mock(SalesOrderService::class, function ($mock): void {
            $mock->shouldReceive('confirmWithChainResult')->once()->andThrow(
                new MissingBomException('No active BOM for product WB-001; material planning cannot run.'),
            );
        });

        $this->actingAs($this->admin())
            ->postJson("/api/v1/crm/sales-orders/{$so->hash_id}/confirm")
            ->assertStatus(422)
            ->assertJson([
                'message' => 'No active BOM for product WB-001; material planning cannot run.',
                'code'    => 'missing_bom',
            ])
            ->assertJsonStructure(['errors' => ['bom']]);
    }

    private function makeOperation(): WoOperation
    {
        $workOrder = WorkOrder::factory()->create();

        return WoOperation::query()->create([
            'work_order_id'  => $workOrder->id,
            'sequence'       => 1,
            'operation_name' => 'Injection',
            'status'         => WoOperationStatus::InProgress->value,
            'qty_planned'    => '100',
        ]);
    }
}
