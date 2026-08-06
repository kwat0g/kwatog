<?php
namespace Tests\Feature\Maintenance;
use App\Modules\Auth\Models\{Role,User};
use Database\Seeders\{RolePermissionSeeder,SettingsSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class DebugMwoTest extends TestCase {
 use RefreshDatabase;
 public function test_debug(): void {
  $this->seed(RolePermissionSeeder::class);
  $this->seed(SettingsSeeder::class);
  $this->withoutExceptionHandling();
  $admin = User::factory()->create(['role_id'=>Role::query()->where('slug','system_admin')->value('id')]);
  $this->actingAs($admin)->getJson('/api/v1/maintenance/work-orders?status=open,assigned,in_progress&per_page=50');
 }
}
