<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tranche B / Task 6 — the check-availability endpoint hands its amount to
 * BudgetEnforcementService as a decimal string.
 *
 * `numeric` is a wider grammar than bcmath's: '1e3' and ' 1' satisfy it but
 * make bccomp/bcadd raise ValueError. The old `(float)` cast absorbed them
 * silently; a plain `(string)` cast would forward them and turn a validated
 * request into an HTTP 500. The amount rule therefore pins the wire format to
 * a canonical decimal, so a malformed amount is a 422 naming the field rather
 * than a server fault — and no float ever stands between the request and the
 * budget verdict.
 */
class BudgetCheckAvailabilityAmountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * The gate short-circuits to 'ok' before touching the amount when there is
     * no active fiscal year or no active budget, so a funded budget is what
     * actually carries the amount into bcmath. FiscalYearFactory yields
     * status=active and BudgetFactory status=approved, which the scopes need.
     */
    private function check(string $amount): TestResponse
    {
        $department = Department::factory()->create();
        $fiscalYear = FiscalYear::factory()->create();

        Budget::factory()->create([
            'fiscal_year_id'  => $fiscalYear->id,
            'department_id'   => $department->id,
            'total_allocated' => '1000000.00',
            'total_spent'     => '0.00',
            'total_committed' => '0.00',
        ]);

        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        return $this->actingAs($user)->getJson(
            '/api/v1/budgets/check-availability?department_id='.$department->id
            .'&amount='.urlencode($amount),
        );
    }

    public function test_a_plain_decimal_amount_is_assessed_against_the_budget(): void
    {
        $this->check('1000.00')
            ->assertOk()
            ->assertJsonPath('data.can_proceed', true)
            ->assertJsonPath('data.level', 'ok');
    }

    public function test_scientific_notation_is_rejected_instead_of_reaching_bcmath(): void
    {
        $this->check('1e3')
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_leading_plus_is_rejected_instead_of_reaching_bcmath(): void
    {
        $this->check('+1000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    /**
     * Asserted as "never a 500" rather than a specific code on purpose: the
     * SanitizeInput middleware trims every string input, so a padded amount
     * arrives here already canonical and is accepted. Should that middleware
     * ever stop covering this field, the rule below rejects the padded form
     * with a 422. Either outcome satisfies the requirement — bcmath must not
     * see the whitespace — so the assertion pins that, not the middleware.
     */
    public function test_surrounding_whitespace_never_reaches_bcmath(): void
    {
        $this->assertNotSame(
            500,
            $this->check(' 1000.00 ')->status(),
            'A padded amount must not reach bcmath and raise a ValueError.',
        );
    }
}
