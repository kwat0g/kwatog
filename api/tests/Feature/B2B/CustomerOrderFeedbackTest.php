<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrderFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    public function test_feedback_requires_a_delivered_order(): void
    {
        [$customer, $portal, $order] = $this->orderWithPortalUser();

        $this->actingAs($portal, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/orders/{$order->hash_id}/feedback", [
                'rating' => 5,
                'comment' => 'Everything arrived safely.',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('customer_order_feedback', 0);
    }

    public function test_customer_can_leave_one_feedback_record_for_a_delivered_order(): void
    {
        [, $portal, $order] = $this->orderWithPortalUser(delivered: true);

        $this->actingAs($portal, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/orders/{$order->hash_id}/feedback", [
                'rating' => 5,
                'comment' => 'Everything arrived safely.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.submitted', true)
            ->assertJsonMissingPath('data.rating');

        $this->actingAs($portal, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/orders/{$order->hash_id}/feedback", [
                'rating' => 4,
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('customer_order_feedback', 1);
    }

    public function test_customer_cannot_submit_feedback_for_another_customers_order(): void
    {
        [, $portal] = $this->orderWithPortalUser();
        $otherOrder = SalesOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $this->actingAs($portal, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/orders/{$otherOrder->hash_id}/feedback", ['rating' => 5])
            ->assertNotFound();
    }

    public function test_rating_and_comment_are_validated(): void
    {
        [, $portal, $order] = $this->orderWithPortalUser(delivered: true);

        $this->actingAs($portal, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/orders/{$order->hash_id}/feedback", [
                'rating' => 6,
                'comment' => str_repeat('a', 2001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating', 'comment']);
    }

    public function test_customer_order_detail_exposes_feedback_state_without_private_feedback_content(): void
    {
        [, $portal, $order] = $this->orderWithPortalUser(delivered: true);
        $this->actingAs($portal, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/orders/{$order->hash_id}/feedback", [
                'rating' => 2,
                'comment' => 'Packaging was damaged.',
            ])
            ->assertCreated();

        $this->actingAs($portal, 'customer_portal')
            ->getJson("/api/v1/b2b/customer/orders/{$order->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.customer_feedback.submitted', true)
            ->assertJsonPath('data.customer_feedback.can_submit', false)
            ->assertJsonMissingPath('data.customer_feedback.rating')
            ->assertJsonMissingPath('data.customer_feedback.comment')
            ->assertDontSee('Packaging was damaged.');
    }

    public function test_feedback_content_is_only_exposed_in_the_internal_sales_order_view(): void
    {
        [$customer, , $order] = $this->orderWithPortalUser(delivered: true);
        $portal = CustomerPortalUser::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->actingAs($portal, 'customer_portal')
            ->postJson("/api/v1/b2b/customer/orders/{$order->hash_id}/feedback", [
                'rating' => 4,
                'comment' => 'Good communication.',
            ])
            ->assertCreated();

        $staff = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/crm/sales-orders/{$order->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.customer_feedback.rating', 4)
            ->assertJsonPath('data.customer_feedback.comment', 'Good communication.')
            ->assertJsonPath('data.customer_feedback.customer_name', 'Portal Customer');
    }

    /** @return array{Customer, CustomerPortalUser, SalesOrder} */
    private function orderWithPortalUser(bool $delivered = false): array
    {
        $customer = Customer::factory()->create();
        $portal = CustomerPortalUser::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Portal Customer',
            'email' => 'feedback-'.uniqid().'@example.test',
            'password' => 'Password1!',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $order = SalesOrder::factory()->create(['customer_id' => $customer->id]);

        if ($delivered) {
            Delivery::query()->create([
                'delivery_number' => 'FB-DEL-'.uniqid(),
                'sales_order_id' => $order->id,
                'status' => 'delivered',
                'scheduled_date' => today(),
                'delivered_at' => now(),
                'created_by' => User::factory()->create()->id,
            ]);
        }

        return [$customer, $portal, $order];
    }
}
