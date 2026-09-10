<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Jobs\ProcessYearEndLeave;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Models\YearEndLeaveDisposition;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LV-01 — max_carryover_days must travel end-to-end: accepted by both
 * FormRequests, persisted by the service, returned by the resource, and
 * honored by the year-end cap logic. NULL means unlimited carryover.
 */
class LeaveTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'              => 'Test Leave',
            'code'              => 'XT'.strtoupper(substr(uniqid(), -6)),
            'default_balance'   => 10,
            'is_paid'           => true,
            'is_active'         => true,
        ], $overrides);
    }

    public function test_store_persists_max_carryover_days_and_returns_it(): void
    {
        $resp = $this->actingAs($this->admin())
            ->postJson('/api/v1/leaves/types', $this->payload(['max_carryover_days' => 5]))
            ->assertCreated();

        $resp->assertJsonPath('data.max_carryover_days', '5.0');

        $lt = LeaveType::query()->where('code', $resp->json('data.code'))->firstOrFail();
        $this->assertSame('5.0', (string) $lt->max_carryover_days);
    }

    public function test_update_changes_max_carryover_days(): void
    {
        $lt = LeaveType::create($this->payload(['max_carryover_days' => 5.0]));

        $this->actingAs($this->admin())
            ->putJson("/api/v1/leaves/types/{$lt->hash_id}", ['max_carryover_days' => 10.5])
            ->assertOk()
            ->assertJsonPath('data.max_carryover_days', '10.5');

        $this->assertSame('10.5', (string) $lt->fresh()->max_carryover_days);
    }

    public function test_omitted_max_carryover_days_stays_null_unlimited(): void
    {
        $resp = $this->actingAs($this->admin())
            ->postJson('/api/v1/leaves/types', $this->payload())
            ->assertCreated();

        $resp->assertJsonPath('data.max_carryover_days', null);

        $lt = LeaveType::query()->where('code', $resp->json('data.code'))->firstOrFail();
        $this->assertNull($lt->max_carryover_days);

        // Omitting the key on update must leave an existing value untouched.
        $this->actingAs($this->admin())
            ->putJson("/api/v1/leaves/types/{$lt->hash_id}", ['name' => 'Renamed Leave'])
            ->assertOk();

        $this->assertNull($lt->fresh()->max_carryover_days);
    }

    public function test_update_with_null_clears_cap_back_to_unlimited(): void
    {
        $lt = LeaveType::create($this->payload(['max_carryover_days' => 5.0]));

        $this->actingAs($this->admin())
            ->putJson("/api/v1/leaves/types/{$lt->hash_id}", ['max_carryover_days' => null])
            ->assertOk()
            ->assertJsonPath('data.max_carryover_days', null);

        $this->assertNull($lt->fresh()->max_carryover_days);
    }

    public function test_store_rejects_out_of_bounds_max_carryover_days(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/leaves/types', $this->payload(['max_carryover_days' => -1]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_carryover_days']);

        $this->actingAs($admin)
            ->postJson('/api/v1/leaves/types', $this->payload(['max_carryover_days' => 366.5]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_carryover_days']);
    }

    public function test_year_end_processing_honors_cap_set_via_api(): void
    {
        $resp = $this->actingAs($this->admin())
            ->postJson('/api/v1/leaves/types', $this->payload([
                'max_carryover_days'      => 5,
                'is_convertible_year_end' => false,
            ]))
            ->assertCreated();

        $lt = LeaveType::query()->where('code', $resp->json('data.code'))->firstOrFail();

        $emp = Employee::factory()->create(['pay_type' => 'monthly', 'basic_monthly_salary' => '22000.00']);
        EmployeeLeaveBalance::create([
            'employee_id' => $emp->id, 'leave_type_id' => $lt->id, 'year' => 2025,
            'total_credits' => 10.0, 'used' => 2.0, 'remaining' => 8.0,
        ]);

        (new ProcessYearEndLeave($this->admin(), 2025))->handle();

        $disp = YearEndLeaveDisposition::where([
            'employee_id' => $emp->id, 'leave_type_id' => $lt->id, 'year' => 2025,
        ])->first();
        $this->assertNotNull($disp);
        $this->assertSame('5.0', (string) $disp->days_carried);   // min(8, cap 5)
        $this->assertSame('3.0', (string) $disp->days_forfeited); // excess over the cap
    }
}
