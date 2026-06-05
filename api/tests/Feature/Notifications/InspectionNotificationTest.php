<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Listeners\NotifyOnInspectionFailed;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_listener_sends_to_production_manager_and_qc_inspector(): void
    {
        $pm = $this->userWithRole('production_manager');
        $qc = $this->userWithRole('qc_inspector');

        $product    = Product::factory()->create();
        $inspection = Inspection::create([
            'inspection_number' => 'QC-202606-0001',
            'stage'             => 'incoming',
            'status'            => 'failed',
            'product_id'        => $product->id,
            'batch_quantity'    => 100,
            'sample_size'       => 10,
        ]);

        $listener = app(NotifyOnInspectionFailed::class);
        $listener->handle(new InspectionFailed($inspection));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $pm->id,
            'type'          => 'quality.inspection_failed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $qc->id,
            'type'          => 'quality.inspection_failed',
        ]);
    }

    public function test_listener_class_exists(): void
    {
        $this->assertTrue(
            class_exists(NotifyOnInspectionFailed::class),
            'Listener class NotifyOnInspectionFailed should exist',
        );
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
