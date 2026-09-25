<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderCreationClassValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_omitted_work_order_class_defaults_to_standard_and_exception_classes_require_a_reason(): void
    {
        $manager = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'production_manager')->value('id'),
        ]);
        $product = Product::factory()->create();
        $payload = [
            'product_id' => $product->hash_id,
            'quantity_target' => 1,
            'planned_start' => now()->addDay()->toDateTimeString(),
            'planned_end' => now()->addDays(2)->toDateTimeString(),
            'priority' => 0,
        ];

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/production/work-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('data.work_order_class', 'standard');

        foreach (['service', 'non_stock', 'prototype'] as $workOrderClass) {
            $this->actingAs($manager, 'sanctum')
                ->postJson('/api/v1/production/work-orders', [
                    ...$payload,
                    'work_order_class' => $workOrderClass,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('exception_reason');
        }
    }
}
