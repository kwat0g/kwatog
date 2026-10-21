<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\SeparationInitiated;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Listeners\NotifyOnSeparationInitiated;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeparationInitiatedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_separation_notice_links_finance_recipients_to_their_authorized_clearance_page(): void
    {
        $clearance = Clearance::factory()->create();
        $finance = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
            'is_active' => true,
        ]);

        app(NotifyOnSeparationInitiated::class)->handle(new SeparationInitiated($clearance));

        $payload = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $finance->id)
            ->where('type', 'chain.separation_initiated')
            ->value('data');

        $this->assertNotNull($payload);
        $this->assertSame('/hr/separations/'.$clearance->hash_id, json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['link_to']);
        $this->assertTrue($finance->hasPermission('hr.separation.view'));
        $this->assertFalse($finance->hasPermission('hr.employees.view'));

        $this->actingAs($finance)
            ->getJson('/api/v1/hr/clearances/'.$clearance->hash_id)
            ->assertOk();
    }
}
