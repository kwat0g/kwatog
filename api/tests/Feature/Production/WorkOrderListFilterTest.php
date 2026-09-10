<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'system_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    public function test_multi_status_array_filter_returns_union_of_statuses(): void
    {
        WorkOrder::factory()->create(['status' => WorkOrderStatus::InProgress->value]);
        WorkOrder::factory()->create(['status' => WorkOrderStatus::Confirmed->value]);
        WorkOrder::factory()->create(['status' => WorkOrderStatus::Paused->value]);
        WorkOrder::factory()->create(['status' => WorkOrderStatus::Planned->value]);
        WorkOrder::factory()->create(['status' => WorkOrderStatus::Completed->value]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/production/work-orders?status[]=in_progress&status[]=confirmed&status[]=paused',
        );

        $response->assertOk()->assertJsonPath('meta.total', 3);

        $statuses = collect($response->json('data'))->pluck('status')->sort()->values()->all();
        $this->assertSame(['confirmed', 'in_progress', 'paused'], $statuses);
    }

    public function test_single_status_string_filter_still_works(): void
    {
        WorkOrder::factory()->create(['status' => WorkOrderStatus::InProgress->value]);
        WorkOrder::factory()->create(['status' => WorkOrderStatus::Confirmed->value]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/production/work-orders?status=in_progress',
        );

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', WorkOrderStatus::InProgress->value);
    }

    public function test_invalid_status_string_is_rejected(): void
    {
        WorkOrder::factory()->create(['status' => WorkOrderStatus::InProgress->value]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/production/work-orders?status=bogus',
        );

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_invalid_status_inside_array_is_rejected(): void
    {
        WorkOrder::factory()->create(['status' => WorkOrderStatus::InProgress->value]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/production/work-orders?status[]=in_progress&status[]=bogus',
        );

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_no_status_filter_returns_all_rows(): void
    {
        WorkOrder::factory()->count(3)->create();

        $response = $this->actingAs($this->user)->getJson('/api/v1/production/work-orders');

        $response->assertOk()->assertJsonPath('meta.total', 3);
    }
}
