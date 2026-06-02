<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
