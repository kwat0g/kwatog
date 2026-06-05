<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function createNotification(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(array_merge([
            'id'              => $id,
            'type'            => 'test.type',
            'notifiable_type' => User::class,
            'notifiable_id'   => $this->user->id,
            'data'            => json_encode(['title' => 'Test', 'message' => 'Msg', 'link_to' => '/test']),
            'read_at'         => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides));

        return $id;
    }

    public function test_index_returns_notifications(): void
    {
        $this->createNotification();

        $response = $this->actingAs($this->user)->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['data' => [['id', 'type', 'data', 'read_at', 'created_at']]]);
    }

    public function test_mark_read(): void
    {
        $id = $this->createNotification();

        $response = $this->actingAs($this->user)->patchJson("/api/v1/notifications/{$id}/read");

        $response->assertOk()->assertJsonPath('data.id', $id);
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_mark_all_read(): void
    {
        $this->createNotification();
        $this->createNotification();

        $response = $this->actingAs($this->user)->patchJson('/api/v1/notifications/read-all');

        $response->assertOk()->assertJsonPath('data.marked_read', 2);
    }

    public function test_delete_single(): void
    {
        $id = $this->createNotification();

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/notifications/{$id}");

        $response->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    public function test_delete_all_read(): void
    {
        $this->createNotification(['read_at' => now()]);
        $unreadId = $this->createNotification(['read_at' => null]);

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/notifications/clear-read');

        $response->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertDatabaseHas('notifications', ['id' => $unreadId]);
    }

    public function test_cannot_delete_other_users_notification(): void
    {
        $id = $this->createNotification();
        DB::table('notifications')->where('id', $id)->update(['notifiable_id' => 99999]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/notifications/{$id}");

        $response->assertNotFound();
    }
}
