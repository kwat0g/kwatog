<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderListFilterTest extends TestCase
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
        SalesOrder::factory()->create(['status' => SalesOrderStatus::Confirmed->value]);
        SalesOrder::factory()->create(['status' => SalesOrderStatus::InProduction->value]);
        SalesOrder::factory()->create(['status' => SalesOrderStatus::PartiallyDelivered->value]);
        SalesOrder::factory()->create(['status' => SalesOrderStatus::Draft->value]);
        SalesOrder::factory()->create(['status' => SalesOrderStatus::Delivered->value]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/crm/sales-orders?status[]=confirmed&status[]=in_production&status[]=partially_delivered',
        );

        $response->assertOk()->assertJsonPath('meta.total', 3);

        $statuses = collect($response->json('data'))->pluck('status')->sort()->values()->all();
        $this->assertSame(['confirmed', 'in_production', 'partially_delivered'], $statuses);
    }

    public function test_single_status_string_filter_still_works(): void
    {
        SalesOrder::factory()->create(['status' => SalesOrderStatus::Confirmed->value]);
        SalesOrder::factory()->create(['status' => SalesOrderStatus::InProduction->value]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/crm/sales-orders?status=confirmed',
        );

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', SalesOrderStatus::Confirmed->value);
    }

    public function test_invalid_status_string_is_rejected(): void
    {
        SalesOrder::factory()->create(['status' => SalesOrderStatus::Confirmed->value]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/crm/sales-orders?status=bogus',
        );

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_invalid_status_inside_array_is_rejected(): void
    {
        SalesOrder::factory()->create(['status' => SalesOrderStatus::Confirmed->value]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/crm/sales-orders?status[]=confirmed&status[]=bogus',
        );

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_no_status_filter_returns_all_rows(): void
    {
        SalesOrder::factory()->count(3)->create();

        $response = $this->actingAs($this->user)->getJson('/api/v1/crm/sales-orders');

        $response->assertOk()->assertJsonPath('meta.total', 3);
    }
}
