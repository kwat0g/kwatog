<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\MRP\Exceptions\BomStructureException;
use Database\Seeders\RolePermissionSeeder;
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
}
