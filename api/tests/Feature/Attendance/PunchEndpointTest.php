<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchEndpointTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        $this->shift();
    }

    private function body(array $over = []): array
    {
        return $over + ['lat' => 14.2860, 'lng' => 120.9345, 'accuracy' => 20];
    }

    public function test_employee_punches_in_then_sees_next_action_out(): void
    {
        [, $user] = $this->worker();
        $this->at('2026-10-07 06:02:00');

        $this->actingAs($user)->postJson('/api/v1/attendance/punch', $this->body())
            ->assertCreated()
            ->assertJsonPath('data.direction', 'in')
            ->assertJsonPath('data.status.next_action', 'out')
            ->assertJsonMissingPath('data.id');

        $this->actingAs($user)->getJson('/api/v1/attendance/punch/status')
            ->assertOk()
            ->assertJsonPath('data.next_action', 'out')
            ->assertJsonCount(1, 'data.punches');
    }

    public function test_out_of_range_returns_422_with_code(): void
    {
        [, $user] = $this->worker();
        $this->at('2026-10-07 06:02:00');

        $this->actingAs($user)->postJson('/api/v1/attendance/punch', $this->body(['lat' => 14.30, 'lng' => 120.95]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'out_of_range');
    }

    public function test_validation_rejects_missing_or_absurd_coordinates(): void
    {
        [, $user] = $this->worker();
        $this->actingAs($user)->postJson('/api/v1/attendance/punch', [])->assertStatus(422)->assertJsonValidationErrors(['lat', 'lng', 'accuracy']);
        $this->actingAs($user)->postJson('/api/v1/attendance/punch', $this->body(['lat' => 123]))->assertStatus(422)->assertJsonValidationErrors(['lat']);
    }

    public function test_user_without_employee_is_forbidden(): void
    {
        $user = User::factory()->create(['employee_id' => null]);
        $this->actingAs($user)->postJson('/api/v1/attendance/punch', $this->body())->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/attendance/punch/status')->assertForbidden();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson('/api/v1/attendance/punch', $this->body())->assertUnauthorized();
    }

    public function test_employee_is_taken_from_session_never_from_body(): void
    {
        [, $user] = $this->worker();
        [$other] = $this->worker();
        $this->at('2026-10-07 06:02:00');

        $this->actingAs($user)->postJson('/api/v1/attendance/punch', $this->body(['employee_id' => $other->id]))->assertCreated();
        $this->assertDatabaseMissing('attendance_punches', ['employee_id' => $other->id]);
    }
}
