<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\SeparationReason;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmploymentHistory;
use App\Modules\HR\Services\SeparationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * M023 audit 2026-08-30 — separation initiation and clearance contract locks.
 *
 * Each test corresponds to a measured defect; see
 * audit/domains/people/separation-final-pay/audit-report.md.
 */
class SeparationContractRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->goodChecklist();
    }

    private function goodChecklist(): void
    {
        $this->setChecklist([
            ['department' => 'HR', 'item_key' => 'exit_interview', 'label' => 'Exit interview'],
            ['department' => 'FIN', 'item_key' => 'accountability', 'label' => 'Accountability'],
        ]);
    }

    /**
     * Settings are cached in Redis, which RefreshDatabase does not reset, so a
     * checklist written by an earlier test can otherwise be served to this one.
     *
     * @param  array<int, array<string, string>>  $items
     */
    private function setChecklist(array $items): void
    {
        Cache::flush();
        app(SettingsService::class)->set('hr.separation.clearance_checklist', $items, 'hr');
        Cache::flush();
    }

    private function actor(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
    }

    private function service(): SeparationService
    {
        return app(SeparationService::class);
    }

    // ── M023-F017: a pre-hire separation date zeroes payroll forever ──────

    public function test_separation_date_before_hire_date_is_refused(): void
    {
        $employee = Employee::factory()->create(['date_hired' => '2024-01-01']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('precedes the hire date');

        $this->service()->initiate($employee, [
            'separation_reason' => SeparationReason::Resigned->value,
            'separation_date' => '2020-06-15',
        ], $this->actor());
    }

    public function test_separation_date_on_the_hire_date_is_allowed(): void
    {
        $employee = Employee::factory()->create(['date_hired' => '2024-01-01']);

        $clearance = $this->service()->initiate($employee, [
            'separation_reason' => SeparationReason::Resigned->value,
            'separation_date' => '2024-01-01',
        ], $this->actor());

        $this->assertSame('2024-01-01', $clearance->separation_date->toDateString());
    }

    public function test_a_future_dated_separation_is_still_allowed(): void
    {
        // Resignation notice periods mean the last working day is normally in
        // the future. This must not be caught by the hire-date guard.
        $employee = Employee::factory()->create(['date_hired' => '2024-01-01']);

        $clearance = $this->service()->initiate($employee, [
            'separation_reason' => SeparationReason::Resigned->value,
            'separation_date' => '2026-12-31',
        ], $this->actor());

        $this->assertSame('2026-12-31', $clearance->separation_date->toDateString());
    }

    // ── M023-F018: duplicate/blank checklist keys ────────────────────────

    public function test_duplicate_checklist_item_key_is_refused_at_initiation(): void
    {
        $this->setChecklist([
            ['department' => 'HR', 'item_key' => 'dup', 'label' => 'First'],
            ['department' => 'FIN', 'item_key' => 'dup', 'label' => 'Second'],
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage("duplicate item_key 'dup'");

        $this->service()->initiate(Employee::factory()->create(), [
            'separation_reason' => SeparationReason::Resigned->value,
            'separation_date' => '2026-05-20',
        ], $this->actor());
    }

    public function test_blank_checklist_field_is_refused_at_initiation(): void
    {
        $this->setChecklist([
            ['department' => '', 'item_key' => 'k', 'label' => 'Label'],
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('blank or non-string department');

        $this->service()->initiate(Employee::factory()->create(), [
            'separation_reason' => SeparationReason::Resigned->value,
            'separation_date' => '2026-05-20',
        ], $this->actor());
    }

    public function test_default_checklist_helper_uses_the_same_validation(): void
    {
        $this->setChecklist([
            ['department' => 'HR', 'item_key' => 'dup', 'label' => 'First'],
            ['department' => 'FIN', 'item_key' => 'dup', 'label' => 'Second'],
        ]);

        $this->expectException(BusinessRuleException::class);
        SeparationService::defaultChecklist();
    }

    // ── M023-F008: initiation remarks must round-trip ────────────────────

    public function test_initiation_remarks_are_persisted_on_the_clearance(): void
    {
        $employee = Employee::factory()->create(['date_hired' => '2024-01-01']);

        $clearance = $this->service()->initiate($employee, [
            'separation_reason' => SeparationReason::Resigned->value,
            'separation_date' => '2026-05-20',
            'remarks' => 'Accepted counter-offer elsewhere.',
        ], $this->actor());

        $this->assertSame('Accepted counter-offer elsewhere.', $clearance->fresh()->remarks);
    }

    // ── M023-F007: employment history array cast contract ────────────────

    public function test_initiation_employment_history_to_value_reads_back_as_an_array(): void
    {
        $employee = Employee::factory()->create(['date_hired' => '2024-01-01']);

        $this->service()->initiate($employee, [
            'separation_reason' => SeparationReason::Resigned->value,
            'separation_date' => '2026-05-20',
        ], $this->actor());

        $history = EmploymentHistory::query()
            ->where('employee_id', $employee->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertIsArray($history->to_value, 'to_value is cast to array and must not be double-encoded.');
        $this->assertIsArray($history->from_value);
        $this->assertSame('resigned', $history->to_value['separation_reason']);
        $this->assertSame('2026-05-20', $history->to_value['separation_date']);
    }

    // ── M023-F010: list contract ─────────────────────────────────────────

    public function test_list_search_filters_by_employee_name_and_clearance_no(): void
    {
        $wanted = Employee::factory()->create(['first_name' => 'Zzunique', 'last_name' => 'Findme']);
        $other = Employee::factory()->create(['first_name' => 'Other', 'last_name' => 'Person']);
        $this->makeClearance($wanted);
        $this->makeClearance($other);

        $this->assertCount(1, $this->service()->list(['search' => 'Zzunique'])->items());
        $this->assertCount(1, $this->service()->list(['search' => 'Findme'])->items());
        $this->assertCount(1, $this->service()->list(['search' => 'Zzunique Findme'])->items());
        $this->assertCount(2, $this->service()->list([])->items());
    }

    public function test_list_per_page_is_clamped_to_a_positive_bounded_value(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->makeClearance(Employee::factory()->create());
        }

        // paginate(0) previously returned every row.
        $this->assertSame(1, $this->service()->list(['per_page' => 0])->perPage());
        $this->assertSame(1, $this->service()->list(['per_page' => -5])->perPage());
        $this->assertSame(1, $this->service()->list(['per_page' => 'abc'])->perPage());
        $this->assertSame(100, $this->service()->list(['per_page' => 99999])->perPage());
        $this->assertSame(25, $this->service()->list(['per_page' => 25])->perPage());
    }

    public function test_list_employee_id_filter_accepts_a_hash_id(): void
    {
        $employee = Employee::factory()->create();
        $this->makeClearance($employee);
        $this->makeClearance(Employee::factory()->create());

        // The SPA sends a HashID; a raw WHERE against a bigint column is a
        // 22P02 invalid-input error.
        $this->assertCount(1, $this->service()->list(['employee_id' => $employee->hash_id])->items());
        $this->assertCount(0, $this->service()->list(['employee_id' => 'not-a-real-hash'])->items());
    }

    // ── M023-F011: no raw signer PK in the payload ───────────────────────

    public function test_clearance_items_do_not_expose_a_raw_signer_id(): void
    {
        $admin = $this->actor();
        $employee = Employee::factory()->create();
        $clearance = $this->makeClearance($employee, [
            'clearance_items' => [
                ['department' => 'HR', 'item_key' => 'k1', 'label' => 'Exit interview',
                    'status' => 'pending', 'signed_by' => null, 'signed_at' => null, 'remarks' => null],
            ],
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/hr/clearances/{$clearance->hash_id}/items", ['item_key' => 'k1'])
            ->assertOk();

        $body = $this->actingAs($admin)
            ->getJson("/api/v1/hr/clearances/{$clearance->hash_id}")
            ->assertOk()
            ->json('data.clearance_items.0');

        $this->assertSame($admin->hash_id, $body['signed_by']);
        $this->assertSame($admin->name, $body['signed_by_name']);
        $this->assertNotSame((string) $admin->id, (string) $body['signed_by']);
    }

    private function makeClearance(Employee $employee, array $overrides = []): Clearance
    {
        return Clearance::create(array_merge([
            'clearance_no' => 'CLR-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'employee_id' => $employee->id,
            'separation_date' => '2026-05-20',
            'separation_reason' => SeparationReason::Resigned->value,
            'clearance_items' => [],
            'status' => ClearanceStatus::InProgress->value,
            'initiated_by' => $this->actor()->id,
        ], $overrides));
    }
}
