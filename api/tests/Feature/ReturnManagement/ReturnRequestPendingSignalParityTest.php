<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReturnRequestPendingSignalParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Cache::flush();
    }

    public function test_pending_returns_badge_matches_the_filtered_list_for_customer_and_supplier_scopes(): void
    {
        foreach (ReturnRequestType::cases() as $type) {
            foreach (ReturnRequestStatus::cases() as $status) {
                ReturnRequest::create([
                    'rma_number' => "RMA-SIGNAL-{$type->value}-{$status->value}",
                    'type' => $type,
                    'status' => $status,
                ]);
            }
        }

        $activeStatuses = array_filter(ReturnRequestStatus::cases(), static fn (ReturnRequestStatus $status): bool => $status->isActive());
        $expectedByRole = [
            'customer_service_officer' => count($activeStatuses),
            'purchasing_officer' => count($activeStatuses) * count(ReturnRequestType::cases()),
        ];

        foreach ($expectedByRole as $roleSlug => $expectedCount) {
            $user = User::factory()->create(['role_id' => Role::query()->where('slug', $roleSlug)->value('id')]);
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/dashboards/badges')
                ->assertOk()
                ->assertJsonPath('data.pending_returns.count', $expectedCount);

            $this->getJson('/api/v1/return-management/return-requests?signal=pending&per_page=100')
                ->assertOk()
                ->assertJsonPath('meta.total', $expectedCount)
                ->assertJsonCount($expectedCount, 'data');
        }
    }

    public function test_return_request_list_rejects_unknown_signal(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/return-management/return-requests?signal=all')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signal');
    }
}
