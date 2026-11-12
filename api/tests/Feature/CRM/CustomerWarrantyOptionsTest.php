<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerWarrantyOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_can_configure_multiple_warranty_choices_for_one_customer_product(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $staff = $this->staff();

        $short = $this->actingAs($staff)->postJson(
            "/api/v1/crm/customers/{$customer->hash_id}/warranty-terms/{$product->hash_id}/options",
            $this->terms('90-day warranty', 90),
        )->assertCreated()->json('data');
        $long = $this->postJson(
            "/api/v1/crm/customers/{$customer->hash_id}/warranty-terms/{$product->hash_id}/options",
            $this->terms('1-year warranty', 365),
        )->assertCreated()->json('data');

        $this->assertNotSame($short['id'], $long['id']);
        $this->getJson("/api/v1/crm/customers/{$customer->hash_id}/warranty-terms")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.product.id', $product->hash_id);
    }

    public function test_warranty_option_validation_and_delete_are_scoped_to_customer_and_product(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $product = Product::factory()->create();
        $otherProduct = Product::factory()->create();
        $staff = $this->staff();
        $path = "/api/v1/crm/customers/{$customer->hash_id}/warranty-terms/{$product->hash_id}/options";

        $this->actingAs($staff)->postJson($path, $this->terms('Invalid', -1))->assertUnprocessable();
        $option = $this->postJson($path, $this->terms('90-day warranty', 90))->assertCreated()->json('data');
        $this->postJson($path, $this->terms('90-day warranty', 180))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label');

        $this->deleteJson("/api/v1/crm/customers/{$other->hash_id}/warranty-terms/{$product->hash_id}/options/{$option['id']}")
            ->assertNotFound();
        $this->deleteJson("/api/v1/crm/customers/{$customer->hash_id}/warranty-terms/{$otherProduct->hash_id}/options/{$option['id']}")
            ->assertNotFound();
        $this->deleteJson("/api/v1/crm/customers/{$customer->hash_id}/warranty-terms/{$product->hash_id}/options/{$option['id']}")
            ->assertNoContent();
    }

    public function test_staff_can_edit_a_warranty_choice_and_updates_remain_scoped(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $product = Product::factory()->create();
        $staff = $this->staff();
        $path = "/api/v1/crm/customers/{$customer->hash_id}/warranty-terms/{$product->hash_id}/options";

        $option = $this->actingAs($staff)->postJson($path, $this->terms('1-year warranty', 365))
            ->assertCreated()->json('data');
        $this->putJson("{$path}/{$option['id']}", [
            ...$this->terms('2-year warranty', 730),
            'additional_terms' => 'Coverage includes corrosion after delivery.',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $option['id'])
            ->assertJsonPath('data.label', '2-year warranty')
            ->assertJsonPath('data.warranty_duration_days', 730)
            ->assertJsonPath('data.additional_terms', 'Coverage includes corrosion after delivery.');

        $this->putJson("/api/v1/crm/customers/{$other->hash_id}/warranty-terms/{$product->hash_id}/options/{$option['id']}", $this->terms('Changed warranty', 90))
            ->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function terms(string $label, int $duration): array
    {
        return [
            'label' => $label,
            'warranty_duration_days' => $duration,
            'warranty_report_window_days' => 30,
            'warranty_start_basis' => 'delivery',
            'warranty_covered_issues' => ['manufacturing_defect'],
            'warranty_remedies' => ['repair', 'replace'],
        ];
    }

    private function staff(): User
    {
        $role = Role::create(['name' => 'Warranty Manager '.uniqid(), 'slug' => 'warranty_manager_'.uniqid()]);
        foreach (['accounting.customers.view', 'accounting.customers.manage'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'crm']);
            $role->permissions()->attach($permission->id);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
