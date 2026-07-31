<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P4.4 — guards the home() → SelfServiceHomeService extraction. Behavior is
 * unchanged: the endpoint returns the same top-level keys.
 */
class SelfServiceHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_service_home_returns_expected_shape_for_linked_employee(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id]);

        $resp = $this->actingAs($user)->getJson('/api/v1/hr/self-service/home');

        $resp->assertOk();
        $resp->assertJsonStructure([
            'data' => [
                'greeting',
                'today',
                'employee' => ['id', 'employee_no', 'first_name', 'full_name', 'department', 'position'],
                'todays_shift',
                'leave_balances',
                'pending_count',
                'latest_payslip',
            ],
        ]);
        // Employee id is a hashid (non-numeric string), never the raw integer.
        $this->assertFalse(ctype_digit((string) $resp->json('data.employee.id')));
    }

    public function test_self_service_home_rejects_user_with_no_linked_employee(): void
    {
        $user = User::factory()->create(['employee_id' => null]);

        $this->actingAs($user)
            ->getJson('/api/v1/hr/self-service/home')
            ->assertStatus(403);
    }

    public function test_self_service_overtime_returns_the_effective_shift_schema(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $shiftId = DB::table('shifts')->insertGetId([
            'name' => 'Audit day shift',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('employee_shift_assignments')->insert([
            'employee_id' => $employee->id,
            'shift_id' => $shiftId,
            'effective_date' => now()->subDay()->toDateString(),
            'end_date' => null,
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/hr/self-service/overtime')
            ->assertOk()
            ->assertJsonPath('data.todays_shift.name', 'Audit day shift')
            ->assertJsonPath('data.todays_shift.time_in', '08:00:00')
            ->assertJsonPath('data.todays_shift.time_out', '17:00:00');
    }

    public function test_self_service_profile_returns_live_employee_fields_and_masks_sensitive_numbers(): void
    {
        $employee = Employee::factory()->create([
            'street_address' => '106 Governor Drive',
            'barangay' => 'Langkaan I',
            'city' => 'Dasmariñas City',
            'province' => 'Cavite',
            'zip_code' => '4114',
            'emergency_contact_name' => 'Jose Cruz',
            'emergency_contact_relation' => 'Parent',
            'emergency_contact_phone' => '+639182000006',
            'bank_name' => 'BDO Unibank',
            'bank_account_no' => '001234560006',
            'sss_no' => '34-2000006-1',
            'philhealth_no' => '12-200000006-2',
            'pagibig_no' => '1234-5678-2006',
            'tin' => '123-456-106-000',
        ]);
        $user = User::factory()->create(['employee_id' => $employee->id]);

        $this->actingAs($user)
            ->getJson('/api/v1/hr/self-service/profile')
            ->assertOk()
            ->assertJsonPath('data.street_address', '106 Governor Drive')
            ->assertJsonPath('data.barangay', 'Langkaan I')
            ->assertJsonPath('data.city', 'Dasmariñas City')
            ->assertJsonPath('data.province', 'Cavite')
            ->assertJsonPath('data.zip_code', '4114')
            ->assertJsonPath('data.emergency_contact_name', 'Jose Cruz')
            ->assertJsonPath('data.emergency_contact_relation', 'Parent')
            ->assertJsonPath('data.emergency_contact_phone', '+639182000006')
            ->assertJsonPath('data.bank_name', 'BDO Unibank')
            ->assertJsonPath('data.bank_account_last4', '••••0006')
            ->assertJsonPath('data.sss_no_last4', '••••06-1')
            ->assertJsonPath('data.philhealth_no_last4', '••••06-2')
            ->assertJsonPath('data.pagibig_no_last4', '••••2006')
            ->assertJsonPath('data.tin_last4', '••••-000')
            ->assertJsonMissing(['bank_account_no' => '001234560006'])
            ->assertJsonMissing(['sss_no' => '34-2000006-1']);
    }
}
